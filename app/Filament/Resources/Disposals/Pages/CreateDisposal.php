<?php

namespace App\Filament\Resources\Disposals\Pages;

use App\Actions\Disposals\RecordDisposal;
use App\Enums\DisposalReason;
use App\Exceptions\ConcurrentModificationException;
use App\Filament\Resources\Disposals\DisposalResource;
use App\Filament\Resources\Disposals\Schemas\DisposalForm;
use App\Models\Asset;
use App\Models\Disposal;
use App\Models\DocumentType;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Filament\Support\Exceptions\Halt;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Arr;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;

/**
 * E26 : sortie d'un bien. Adresse /admin/sorties/create, préremplissage par ?bien=<id>&motif=<motif>
 * (contrat avec les lots 8 et 9) et ?inventaire_ligne=<id> (régularisation d'inventaire, lot 9).
 * RG-53 : l'enregistrement passe uniquement par « Confirmer la sortie », avec la saisie du numéro.
 */
class CreateDisposal extends CreateRecord
{
    protected static string $resource = DisposalResource::class;

    protected static ?string $title = 'Sortir un bien du patrimoine';

    protected static bool $canCreateAnother = false;

    // RG-53 : « Confirmer » porte sa propre retentative ; aucune transaction autour.
    protected ?bool $hasDatabaseTransactions = false;

    /**
     * RG-86 : version de chaque bien choisi, lue côté serveur au moment du choix.
     *
     * @var array<int, int>
     */
    #[Locked]
    public array $assetVersions = [];

    #[Locked]
    public ?int $inventoryLineId = null;

    // Renseigné seulement par l'action « Confirmer la sortie », jamais par le navigateur.
    private ?string $confirmation = null;

    public function mount(): void
    {
        parent::mount();

        $prefill = [];
        $assetId = request()->query('bien');

        if (is_string($assetId) && ctype_digit($assetId)) {
            $asset = DisposalForm::eligibleAssets()->find((int) $assetId);

            if ($asset instanceof Asset) {
                $prefill['asset_id'] = $asset->getKey();
                $this->rememberAssetVersion((int) $asset->getKey());
            }
        }

        $reason = request()->query('motif');

        if (is_string($reason) && DisposalReason::tryFrom($reason) !== null) {
            $prefill['reason'] = $reason;
        }

        $line = request()->query('inventaire_ligne');

        if (is_string($line) && ctype_digit($line)) {
            $this->inventoryLineId = (int) $line;
        }

        if ($prefill !== []) {
            $this->form->fill([...$this->form->getRawState(), ...$prefill]);
        }
    }

    public function rememberAssetVersion(?int $assetId): void
    {
        $asset = $assetId === null ? null : DisposalForm::eligibleAssets()->find($assetId);

        if ($asset instanceof Asset) {
            $this->assetVersions[$assetId] = $asset->lock_version;
        }
    }

    protected function getFormActions(): array
    {
        return [
            $this->getConfirmFormAction(),
            $this->getCancelFormAction(),
        ];
    }

    protected function getConfirmFormAction(): Action
    {
        return Action::make('confirmDisposal')
            ->label('Confirmer la sortie')
            ->color('danger')
            ->modalHeading('Confirmer la sortie du patrimoine')
            ->modalDescription(function (): string {
                $asset = DisposalForm::eligibleAssets()->find($this->data['asset_id'] ?? null);

                return 'Cette opération est irréversible : la sortie ne pourra pas être annulée dans l\'application'
                    .($asset instanceof Asset ? ' et la fiche '.$asset->number.' passera en lecture seule' : '')
                    .'. Saisissez le numéro du bien pour confirmer.';
            })
            ->modalSubmitActionLabel('Sortir définitivement du patrimoine')
            ->modalIcon('heroicon-o-exclamation-triangle')
            ->modalIconColor('danger')
            ->schema([
                TextInput::make('confirmation')
                    ->label('Numéro du bien')
                    ->placeholder('PAT-000000')
                    ->required()
                    ->autocomplete(false),
            ])
            ->action(function (array $data): void {
                $this->confirmation = is_string($data['confirmation'] ?? null) ? $data['confirmation'] : null;
                $this->create();
            });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordCreation(array $data): Disposal
    {
        $confirmation = $this->confirmation;
        $this->confirmation = null;

        if ($confirmation === null) {
            $this->fail('Utilisez « Confirmer la sortie » et saisissez le numéro du bien (RG-53).');
        }

        $assetId = (int) Arr::pull($data, 'asset_id');
        $files = Arr::wrap(Arr::pull($data, 'files'));
        $typeId = Arr::pull($data, 'document_type_id');

        // RG-86 : sans version lue à l'ouverture, on ne sait pas si les valeurs affichées sont à jour.
        if (! array_key_exists($assetId, $this->assetVersions)) {
            $this->fail('Le bien choisi n\'a pas pu être vérifié. Rechargez la page et choisissez-le à nouveau.');
        }

        try {
            return app(RecordDisposal::class)(
                $assetId,
                $data,
                $confirmation,
                $this->assetVersions[$assetId],
                $files,
                filled($typeId) ? DocumentType::query()->active()->find($typeId) : null,
                $this->inventoryLineId,
            );
        } catch (AuthorizationException) {
            abort(403);
        } catch (ConcurrentModificationException $exception) {
            $this->fail($exception->getMessage(), 'La fiche du bien a été modifiée entre-temps');
        } catch (ValidationException $exception) {
            $fields = collect($exception->errors())
                ->except('confirmation')
                ->mapWithKeys(fn (array $messages, string $field): array => ['data.'.$field => $messages])
                ->all();

            if ($fields === []) {
                $this->fail(collect($exception->errors())->flatten()->implode(' '));
            }

            Notification::make()->danger()->title('Sortie refusée')->body(collect($exception->errors())->flatten()->implode(' '))->persistent()->send();

            throw ValidationException::withMessages($fields);
        }
    }

    private function fail(string $message, string $title = 'Sortie refusée'): never
    {
        Notification::make()->danger()->title($title)->body($message)->persistent()->send();

        throw new Halt;
    }

    protected function getCreatedNotificationTitle(): ?string
    {
        /** @var Disposal $disposal */
        $disposal = $this->getRecord();

        return 'Sortie '.$disposal->reference.' enregistrée : traitement comptable à valider';
    }

    protected function getRedirectUrl(): string
    {
        return DisposalResource::getUrl('view', ['record' => $this->getRecord()]);
    }
}
