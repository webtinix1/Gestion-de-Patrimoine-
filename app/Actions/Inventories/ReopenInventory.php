<?php

namespace App\Actions\Inventories;

use App\Enums\InventoryStatus;
use App\Models\Inventory;
use App\Models\User;
use App\Services\Inventories\InventoryJournal;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Reprend la saisie d'un inventaire « à valider » (avant clôture seulement : la clôture est définitive).
 */
class ReopenInventory
{
    public function __construct(private InventoryJournal $journal) {}

    public function __invoke(Inventory $inventory, User $user): Inventory
    {
        return DB::transaction(function () use ($inventory, $user): Inventory {
            $current = Inventory::query()->lockForUpdate()->findOrFail($inventory->getKey());

            if ($current->status !== InventoryStatus::ToValidate) {
                throw ValidationException::withMessages(['inventory' => 'Seul un inventaire à valider peut être rouvert.']);
            }

            if (! $user->can('reopen', $current)) {
                throw new AuthorizationException('Vous n\'avez pas le droit de rouvrir cet inventaire.');
            }

            $current->forceFill(['status' => InventoryStatus::InProgress, 'submitted_at' => null, 'submitted_by' => null])->save();

            $this->journal->inventory($current, 'reopened', 'Reprise de la saisie d\'un inventaire',
                ['statut' => InventoryStatus::ToValidate->getLabel()],
                ['statut' => InventoryStatus::InProgress->getLabel()],
                user: $user,
            );

            $inventory->setRawAttributes($current->getAttributes(), sync: true);

            return $inventory;
        }, attempts: 5);
    }
}
