<?php

namespace App\Filament\Resources\Inventories\Pages;

use App\Actions\Inventories\CloseInventory;
use App\Actions\Inventories\DeclareRemainingMissing;
use App\Actions\Inventories\ExportInventoryReport;
use App\Actions\Inventories\RegularizeInventoryLine;
use App\Actions\Inventories\ReopenInventory;
use App\Enums\AssetStatus;
use App\Enums\InventoryLineResult;
use App\Enums\PermissionAbility;
use App\Enums\PermissionModule;
use App\Exceptions\ConcurrentModificationException;
use App\Filament\Actions\TransferAction;
use App\Filament\Resources\Assets\AssetResource;
use App\Filament\Resources\Inventories\InventoryResource;
use App\Models\Asset;
use App\Models\AssetCategory;
use App\Models\AssetCondition;
use App\Models\Department;
use App\Models\Inventory;
use App\Models\InventoryLine;
use App\Models\Movement;
use App\Models\Room;
use App\Models\Service;
use App\Models\Site;
use App\Models\User;
use App\Services\Inventories\InventoryReport;
use Filament\Actions\Action;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\Concerns\InteractsWithRecord;
use Filament\Resources\Pages\Page;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

/**
 * E21 : rapport d'inventaire par type d'écart, validation et clôture (RG-48), régularisations
 * tracées depuis le rapport après la clôture, PV PDF et export Excel (RG-49). Limité aux lignes du
 * périmètre de l'utilisateur (RG-81). Aucune règle ici : tout passe par les actions métier.
 */
class InventoryReportPage extends Page
{
    use InteractsWithRecord;

    protected static string $resource = InventoryResource::class;

    protected string $view = 'filament.resources.inventories.pages.inventory-report';

    public static function canAccess(array $parameters = []): bool
    {
        $user = auth()->user();
        $record = $parameters['record'] ?? null;

        return $user instanceof User && $user->can('viewAny', Inventory::class)
            && (! $record instanceof Inventory || $user->can('view', $record));
    }

    public function mount(int|string $record): void
    {
        $this->record = $this->resolveRecord($record);
    }

    public function inventory(): Inventory
    {
        /** @var Inventory $inventory */
        $inventory = $this->getRecord();

        return $inventory;
    }

    public function getTitle(): string|Htmlable
    {
        return 'Rapport d\'inventaire · '.$this->inventory()->scopeLabel();
    }

    public function getSubheading(): string
    {
        $inventory = $this->inventory();

        return $inventory->reference.' · du '.$inventory->periodLabel().' · responsable '.$inventory->manager->fullName();
    }

    public function getBreadcrumb(): string
    {
        return 'Rapport';
    }

    /**
     * @return array<string, int>
     */
    public function getCounters(): array
    {
        return app(InventoryReport::class)->counters($this->inventory());
    }

    /**
     * @return array<string, array{label: string, color: string, lines: Collection<int, InventoryLine>}>
     */
    public function getSections(): array
    {
        return app(InventoryReport::class)->sections($this->inventory());
    }

    /**
     * @return Collection<int, InventoryLine>
     */
    public function getObservations(): Collection
    {
        return app(InventoryReport::class)->observations($this->inventory());
    }

    public function canRegularize(): bool
    {
        return $this->user()->can('regularize', $this->inventory());
    }

    public function disposalUrl(InventoryLine $line): ?string
    {
        return $this->canRegularize() && $line->resolution === null && $line->result === InventoryLineResult::Missing
            && $this->user()->can(PermissionModule::Disposals->permission(PermissionAbility::Create)) ? RegularizeInventoryLine::disposalUrl($line) : null;
    }

    public function assetUrl(?Asset $asset): ?string
    {
        return $asset ? AssetResource::getUrl('view', ['record' => $asset]) : null;
    }

    public function resolutionLabel(InventoryLine $line): string
    {
        $label = $line->resolution?->getLabel() ?? '';
        $reference = $line->resolutionRef;

        return match (true) {
            $reference instanceof Asset => $label.' · '.$reference->number,
            $reference instanceof Movement => $label.' le '.$reference->moved_on->format('d/m/Y'),
            default => $label.($line->resolved_at ? ' le '.$line->resolved_at->format('d/m/Y') : ''),
        };
    }

    public function transferAction(): Action
    {
        return Action::make('transfer')
            ->label('Régulariser')
            ->link()
            ->modalHeading('Régulariser par un transfert')
            ->modalDescription('Un mouvement est enregistré vers l\'affectation constatée (RG-21), puis lié à la ligne de l\'inventaire.')
            ->modalSubmitActionLabel('Enregistrer le transfert')
            ->visible(fn (): bool => $this->canRegularize() && $this->user()->can('create', Movement::class))
            ->fillForm(function (array $arguments): array {
                $line = $this->line($arguments);
                $asset = $line?->asset;

                if (! $line instanceof InventoryLine || ! $asset instanceof Asset) {
                    return [];
                }

                $departmentId = $line->found_department_id ?? $asset->department_id;

                return [
                    'site_id' => $line->found_site_id ?? $asset->site_id,
                    'department_id' => $departmentId,
                    'service_id' => $departmentId === $asset->department_id ? $asset->service_id : null,
                    'room_id' => $line->found_room_id ?? $asset->room_id,
                    'holder_id' => $asset->holder_id,
                    'moved_on' => today()->toDateString(),
                    'reason' => RegularizeInventoryLine::TRANSFER_REASON,
                    'lock_version' => $asset->lock_version,
                ];
            })
            ->schema([
                Hidden::make('lock_version'),
                Hidden::make('holder_id'),
                Select::make('site_id')->label('Site')->required()->live()
                    ->options(fn (): array => Site::query()->where('is_active', true)->orderBy('name')->pluck('name', 'id')->all())
                    ->afterStateUpdated(fn (Set $set) => $set('room_id', null)),
                Select::make('department_id')->label('Département')->required()->live()
                    ->options(fn (): array => Department::query()->where('is_active', true)->orderBy('name')->pluck('name', 'id')->all())
                    ->afterStateUpdated(fn (Set $set) => $set('service_id', null)),
                Select::make('service_id')->label('Service')->placeholder('Aucun')
                    ->options(fn (Get $get): array => Service::query()->where('is_active', true)->where('department_id', $get('department_id'))->orderBy('name')->pluck('name', 'id')->all()),
                Select::make('room_id')->label('Local')->placeholder('Aucun')
                    ->options(fn (Get $get): array => Room::query()->where('is_active', true)->where('site_id', $get('site_id'))->orderBy('name')->pluck('name', 'id')->all()),
                DatePicker::make('moved_on')->label('Date du transfert')->required()->maxDate(today()),
                TextInput::make('reason')->label('Motif')->datalist(TransferAction::REASONS)->maxLength(255),
                Textarea::make('notes')->label('Observations')->rows(2),
            ])
            ->action(function (array $data, array $arguments, Action $action): void {
                $this->run($action, 'Bien transféré, ligne régularisée', fn (InventoryLine $line) => app(RegularizeInventoryLine::class)
                    ->transfer($line, $this->user(), $data, isset($data['lock_version']) ? (int) $data['lock_version'] : null), $arguments);
            });
    }

    public function conditionAction(): Action
    {
        return Action::make('condition')
            ->label('Mettre à jour l\'état')
            ->link()
            ->requiresConfirmation()
            ->modalHeading('Mettre à jour l\'état de la fiche')
            ->modalDescription(fn (array $arguments): string => 'La fiche prendra l\'état constaté « '.($this->line($arguments)?->foundCondition->label ?? '—').' ». La modification est tracée sur la fiche et liée à la ligne.')
            ->modalSubmitActionLabel('Mettre à jour')
            ->visible(fn (): bool => $this->canRegularize() && $this->user()->can(PermissionModule::Assets->permission(PermissionAbility::Update)))
            ->fillForm(fn (array $arguments): array => ['lock_version' => $this->line($arguments)?->asset?->lock_version])
            ->schema([Hidden::make('lock_version')])
            ->action(function (array $data, array $arguments, Action $action): void {
                $this->run($action, 'État de la fiche mis à jour', fn (InventoryLine $line) => app(RegularizeInventoryLine::class)
                    ->updateCondition($line, $this->user(), isset($data['lock_version']) ? (int) $data['lock_version'] : null), $arguments);
            });
    }

    public function createAssetAction(): Action
    {
        return Action::make('createAsset')
            ->label('Créer la fiche')
            ->link()
            ->modalHeading('Créer la fiche du bien trouvé')
            ->modalDescription('Le numéro patrimonial est attribué à l\'enregistrement ; la fiche est liée à la ligne de l\'inventaire.')
            ->modalSubmitActionLabel('Créer la fiche')
            ->modalWidth('3xl')
            ->visible(fn (): bool => $this->canRegularize() && $this->user()->can('create', Asset::class))
            ->fillForm(function (array $arguments): array {
                $line = $this->line($arguments);

                return [
                    'designation' => $line?->description,
                    'site_id' => $line?->found_site_id,
                    'department_id' => $line?->found_department_id,
                    'room_id' => $line?->found_room_id,
                    'asset_condition_id' => $line?->found_condition_id,
                    'status' => AssetStatus::InService->value,
                    'acquired_on' => today()->toDateString(),
                ];
            })
            ->schema([
                TextInput::make('designation')->label('Désignation')->required()->maxLength(255)->columnSpanFull(),
                Select::make('asset_category_id')->label('Catégorie')->required()
                    ->options(fn (): array => AssetCategory::query()->where('is_active', true)->orderBy('name')->pluck('name', 'id')->all()),
                DatePicker::make('acquired_on')->label('Date d\'acquisition (ou d\'entrée)')->required()->maxDate(today()),
                TextInput::make('acquisition_cost')->label('Prix d\'acquisition (FCFA)')->integer()->minValue(0)
                    ->helperText('Obligatoire si le bien est amortissable.'),
                Select::make('asset_condition_id')->label('État')->required()
                    ->options(fn (): array => AssetCondition::query()->active()->orderBy('sort')->pluck('label', 'id')->all()),
                Select::make('site_id')->label('Site')->required()->live()
                    ->options(fn (): array => Site::query()->where('is_active', true)->orderBy('name')->pluck('name', 'id')->all())
                    ->afterStateUpdated(fn (Set $set) => $set('room_id', null)),
                Select::make('department_id')->label('Département')->required()
                    ->options(fn (): array => Department::query()->where('is_active', true)->orderBy('name')->pluck('name', 'id')->all()),
                Select::make('room_id')->label('Local')->placeholder('Aucun')
                    ->options(fn (Get $get): array => Room::query()->where('is_active', true)->where('site_id', $get('site_id'))->orderBy('name')->pluck('name', 'id')->all()),
                Select::make('status')->label('Statut')->required()
                    ->options(collect(AssetStatus::cases())->filter(fn (AssetStatus $status): bool => $status->isManuallySelectable())
                        ->mapWithKeys(fn (AssetStatus $status): array => [$status->value => $status->getLabel()])->all()),
            ])
            ->action(function (array $data, array $arguments, Action $action): void {
                $this->run($action, 'Fiche créée et liée à la ligne', fn (InventoryLine $line) => app(RegularizeInventoryLine::class)
                    ->createAsset($line, $this->user(), $data), $arguments);
            });
    }

    public function foundAction(): Action
    {
        return Action::make('found')
            ->label('Marquer retrouvé')
            ->link()
            ->modalHeading('Bien retrouvé après la clôture')
            ->modalDescription('Le résultat « manquant » de l\'inventaire reste figé (RG-48) ; la régularisation note que le bien a été retrouvé.')
            ->modalSubmitActionLabel('Enregistrer')
            ->visible(fn (): bool => $this->canRegularize())
            ->schema([Textarea::make('notes')->label('Où et quand a-t-il été retrouvé ?')->required()->rows(2)])
            ->action(function (array $data, array $arguments, Action $action): void {
                $this->run($action, 'Bien noté retrouvé', fn (InventoryLine $line) => app(RegularizeInventoryLine::class)
                    ->markFound($line, $this->user(), (string) ($data['notes'] ?? '')), $arguments);
            });
    }

    public function closeAction(): Action
    {
        return Action::make('close')
            ->label('Valider et clôturer l\'inventaire')
            ->authorize(fn (): bool => $this->user()->can('close', $this->inventory()))
            ->modalHeading('Valider et clôturer '.$this->inventory()->reference)
            ->modalDescription('Le résultat validé est figé et conservé. Aucune fiche n\'est modifiée : les régularisations se font ensuite depuis ce rapport, chacune tracée (RG-48).')
            ->modalSubmitActionLabel('Valider et clôturer')
            ->schema([
                Textarea::make('comment')->label('Commentaire de validation')->rows(3),
                Checkbox::make('attested')->label('J\'atteste que le comptage a été réalisé sur place, avec les responsables concernés.')->accepted(),
            ])
            ->action(function (array $data, Action $action): void {
                try {
                    app(CloseInventory::class)($this->inventory(), $this->user(), $data['comment'] ?? null, (bool) ($data['attested'] ?? false));
                } catch (ValidationException $exception) {
                    Notification::make()->danger()->title('Clôture impossible')->body((string) collect($exception->errors())->flatten()->first())->send();
                    $action->halt();

                    return;
                }

                Notification::make()->success()->title('Inventaire '.$this->inventory()->reference.' clôturé')->send();
                $this->record = $this->resolveRecord($this->inventory()->getKey());
            });
    }

    public function declareMissingAction(): Action
    {
        return Action::make('declareMissing')
            ->label(fn (): string => 'Déclarer manquants les '.$this->getCounters()['pending'].' biens non vérifiés')
            ->color('danger')
            ->outlined()
            ->authorize(fn (): bool => $this->user()->can('close', $this->inventory()))
            ->visible(fn (): bool => $this->getCounters()['pending'] > 0)
            ->requiresConfirmation()
            ->modalDescription('Chaque bien attendu encore « à vérifier » est enregistré « manquant » à votre nom (tracé bien par bien).')
            ->action(function (): void {
                $count = app(DeclareRemainingMissing::class)($this->inventory(), $this->user());
                Notification::make()->success()->title($count.' bien'.($count > 1 ? 's déclarés manquants' : ' déclaré manquant'))->send();
            });
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('enter')
                ->label('Continuer la saisie')
                ->icon('heroicon-o-qr-code')
                ->visible(fn (): bool => $this->user()->can('enter', $this->inventory()))
                ->url(fn (): string => InventoryResource::getUrl('enter', ['record' => $this->inventory()])),
            Action::make('reopen')
                ->label('Reprendre la saisie')
                ->icon('heroicon-o-arrow-uturn-left')
                ->color('gray')
                ->authorize(fn (): bool => $this->user()->can('reopen', $this->inventory()))
                ->requiresConfirmation()
                ->action(function (): void {
                    app(ReopenInventory::class)($this->inventory(), $this->user());
                    $this->redirect(InventoryResource::getUrl('enter', ['record' => $this->inventory()]));
                }),
            Action::make('exportExcel')
                ->label('Exporter Excel')
                ->icon('heroicon-o-table-cells')
                ->color('gray')
                ->visible(fn (): bool => $this->user()->can('export', $this->inventory()))
                ->action(fn (): Response => app(ExportInventoryReport::class)->excel($this->inventory(), $this->user())),
            Action::make('exportPdf')
                ->label(fn (): string => $this->inventory()->isClosed() ? 'Procès-verbal (PDF)' : 'Exporter PDF')
                ->icon('heroicon-o-document-text')
                ->color('gray')
                ->visible(fn (): bool => $this->user()->can('export', $this->inventory()))
                ->action(fn (): Response => app(ExportInventoryReport::class)->pdf($this->inventory(), $this->user())),
        ];
    }

    /**
     * @param  array<string, mixed>  $arguments
     */
    private function line(array $arguments): ?InventoryLine
    {
        // RG-81 : la ligne doit appartenir à cet inventaire et au périmètre de l'utilisateur.
        return InventoryLine::query()->where('inventory_id', $this->inventory()->id)->find((int) ($arguments['line'] ?? 0));
    }

    /**
     * @param  array<string, mixed>  $arguments
     */
    private function run(Action $action, string $success, \Closure $operation, array $arguments): void
    {
        $line = $this->line($arguments);

        if (! $line instanceof InventoryLine) {
            Notification::make()->danger()->title('Ligne introuvable dans votre périmètre')->send();
            $action->halt();

            return;
        }

        try {
            $operation($line);
        } catch (ValidationException $exception) {
            Notification::make()->danger()->title('Régularisation impossible')->body((string) collect($exception->errors())->flatten()->first())->send();
            $action->halt();

            return;
        } catch (ConcurrentModificationException $exception) {
            Notification::make()->danger()->title('Fiche modifiée entre-temps')->body($exception->getMessage())->persistent()->send();
            $action->halt();

            return;
        } catch (AuthorizationException $exception) {
            Notification::make()->danger()->title('Action non autorisée')->body($exception->getMessage())->send();
            $action->halt();

            return;
        }

        Notification::make()->success()->title($success)->send();
    }

    private function user(): User
    {
        $user = auth()->user();
        abort_unless($user instanceof User, 403);

        return $user;
    }
}
