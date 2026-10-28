<?php

namespace App\Filament\Resources\Inventories\Pages;

use App\Actions\Inventories\StartInventory;
use App\Actions\Inventories\UpdateInventory;
use App\Exceptions\ConcurrentModificationException;
use App\Filament\Resources\Inventories\InventoryResource;
use App\Filament\Support\DetectsConcurrentEdits;
use App\Models\Inventory;
use App\Models\InventoryParticipant;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

/**
 * Modification d'un inventaire planifié et démarrage (la liste est figée au démarrage, RG-44).
 */
class EditInventory extends EditRecord
{
    use DetectsConcurrentEdits;

    protected static string $resource = InventoryResource::class;

    public function getTitle(): string|Htmlable
    {
        /** @var Inventory $inventory */
        $inventory = $this->getRecord();

        return 'Modifier '.$inventory->reference;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        /** @var Inventory $inventory */
        $inventory = $this->getRecord();

        $data['participants'] = $inventory->participants->map(fn (InventoryParticipant $participant): array => [
            'user_id' => $participant->user_id,
            'room_id' => $participant->room_id,
        ])->all();

        return $data;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        /** @var Inventory $record */
        /** @var User $user */
        $user = auth()->user();

        try {
            return app(UpdateInventory::class)($record, $data, $user, $this->openedLockVersion);
        } catch (ConcurrentModificationException $exception) {
            $this->notifyConcurrentModification($exception);

            return $record;
        }
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('start')
                ->label('Démarrer l\'inventaire')
                ->icon('heroicon-o-play')
                // L'action reste appelable depuis une page restée ouverte : StartInventory revérifie et explique.
                ->authorize(fn (): bool => auth()->user()?->can('create', Inventory::class) ?? false)
                ->requiresConfirmation()
                ->modalHeading('Démarrer l\'inventaire')
                ->modalDescription('La liste des biens attendus est figée maintenant : un bien ajouté au périmètre ensuite n\'y entrera pas. Les modifications non enregistrées de ce formulaire sont abandonnées.')
                ->modalSubmitActionLabel('Démarrer')
                ->action(function (Action $action): void {
                    /** @var Inventory $inventory */
                    $inventory = $this->getRecord();
                    /** @var User $user */
                    $user = auth()->user();

                    try {
                        app(StartInventory::class)($inventory, $user);
                    } catch (ValidationException|AuthorizationException $exception) {
                        Notification::make()->danger()->title('Démarrage impossible')
                            ->body($exception instanceof ValidationException ? (string) collect($exception->errors())->flatten()->first() : $exception->getMessage())
                            ->send();
                        $action->halt();

                        return;
                    }

                    Notification::make()->success()->title('Inventaire '.$inventory->reference.' démarré : liste des biens figée')->send();
                    $this->redirect(InventoryResource::getUrl('enter', ['record' => $inventory]));
                }),
        ];
    }

    protected function getRedirectUrl(): string
    {
        return InventoryResource::getUrl('index');
    }
}
