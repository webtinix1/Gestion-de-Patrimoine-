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
 * « Terminer la saisie » : l'inventaire passe « à valider » ; plus aucune saisie n'est possible.
 * L'utilisateur qui a le droit « valider » peut le rouvrir (ReopenInventory) avant la clôture.
 */
class SubmitInventory
{
    public function __construct(private InventoryJournal $journal) {}

    public function __invoke(Inventory $inventory, User $user): Inventory
    {
        return DB::transaction(function () use ($inventory, $user): Inventory {
            $current = Inventory::query()->lockForUpdate()->findOrFail($inventory->getKey());

            if ($current->status !== InventoryStatus::InProgress) {
                throw ValidationException::withMessages(['inventory' => 'Seul un inventaire en cours peut être terminé.']);
            }

            if (! $user->can('submit', $current)) {
                throw new AuthorizationException('Vous n\'êtes pas désigné pour saisir cet inventaire.');
            }

            $current->forceFill(['status' => InventoryStatus::ToValidate, 'submitted_at' => now(), 'submitted_by' => $user->id])->save();

            $this->journal->inventory($current, 'submitted', 'Fin de la saisie d\'un inventaire',
                ['statut' => InventoryStatus::InProgress->getLabel()],
                ['statut' => InventoryStatus::ToValidate->getLabel()],
                user: $user,
            );

            $inventory->setRawAttributes($current->getAttributes(), sync: true);

            return $inventory;
        }, attempts: 5);
    }
}
