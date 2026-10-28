<?php

namespace App\Filament\Actions;

use App\Actions\Inventories\AddAssetsToInventory;
use App\Enums\InventoryStatus;
use App\Models\Inventory;
use App\Models\User;
use Filament\Actions\BulkAction;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Text;
use Filament\Support\Icons\Heroicon;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * E05 : action groupée « Ajouter à un inventaire » (inventaire planifié seulement, RG-44, QL9-3).
 * Aucune règle ici : tout passe par App\Actions\Inventories\AddAssetsToInventory.
 */
final class AddToInventoryAction
{
    public static function bulk(): BulkAction
    {
        return BulkAction::make('addToInventory')
            ->label('Ajouter à un inventaire')
            ->icon(Heroicon::OutlinedClipboardDocumentCheck)
            ->authorize(fn (): bool => auth()->user()?->can('create', Inventory::class) ?? false)
            ->modalHeading('Ajouter les biens sélectionnés à un inventaire')
            ->modalSubmitActionLabel('Ajouter')
            ->deselectRecordsAfterCompletion()
            ->schema([
                Text::make('Seul un inventaire planifié reçoit des biens : sa liste est figée au démarrage, avec les biens de son périmètre.'),
                Select::make('inventory_id')
                    ->label('Inventaire planifié')
                    ->required()
                    ->options(fn (): array => Inventory::query()->where('status', InventoryStatus::Planned)->orderByDesc('reference')->get()
                        ->filter(fn (Inventory $inventory): bool => auth()->user()?->can('update', $inventory) ?? false)
                        ->mapWithKeys(fn (Inventory $inventory): array => [$inventory->id => $inventory->reference.' · '.$inventory->scopeLabel()])
                        ->all()),
            ])
            ->action(function (array $data, Collection $records, BulkAction $action): void {
                $user = auth()->user();
                $inventory = Inventory::query()->find($data['inventory_id'] ?? null);

                if (! $user instanceof User || ! $inventory instanceof Inventory) {
                    Notification::make()->danger()->title('Inventaire introuvable')->send();
                    $action->halt();

                    return;
                }

                try {
                    $added = app(AddAssetsToInventory::class)($inventory, $records->all(), $user);
                } catch (ValidationException|AuthorizationException $exception) {
                    $message = $exception instanceof ValidationException ? (string) collect($exception->errors())->flatten()->first() : $exception->getMessage();
                    Notification::make()->danger()->title('Ajout impossible')->body($message)->send();
                    $action->halt();

                    return;
                }

                Notification::make()->success()
                    ->title($added === 0 ? 'Ces biens figuraient déjà dans '.$inventory->reference : $added.' bien'.($added > 1 ? 's ajoutés' : ' ajouté').' à '.$inventory->reference)
                    ->send();
            });
    }
}
