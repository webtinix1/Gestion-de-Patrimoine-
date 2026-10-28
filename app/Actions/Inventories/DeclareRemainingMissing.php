<?php

namespace App\Actions\Inventories;

use App\Enums\InventoryLineResult;
use App\Enums\InventoryStatus;
use App\Models\Inventory;
use App\Models\InventoryLine;
use App\Models\User;
use App\Services\Inventories\InventoryJournal;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Avant la clôture : les biens attendus restés « à vérifier » sont déclarés manquants, sur décision
 * explicite de la personne qui valide (QL9-1). Une entrée de journal par bien (RG-70).
 */
class DeclareRemainingMissing
{
    public function __construct(private InventoryJournal $journal) {}

    public function __invoke(Inventory $inventory, User $user): int
    {
        return DB::transaction(function () use ($inventory, $user): int {
            $current = Inventory::query()->lockForUpdate()->findOrFail($inventory->getKey());

            if ($current->status !== InventoryStatus::ToValidate) {
                throw ValidationException::withMessages(['inventory' => 'Terminez d\'abord la saisie de l\'inventaire.']);
            }

            if (! $user->can('close', $current)) {
                throw new AuthorizationException('Vous n\'avez pas le droit de valider cet inventaire.');
            }

            $lines = InventoryLine::query()->withoutGlobalScopes()
                ->where('inventory_id', $current->id)
                ->where('result', InventoryLineResult::Pending)
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            foreach ($lines as $line) {
                $line->forceFill(['result' => InventoryLineResult::Missing, 'checked_by' => $user->id, 'checked_at' => now()])->save();
                $this->journal->line($line, $current, ['result' => InventoryLineResult::Pending], $user, 'Bien non vérifié déclaré manquant');
            }

            return $lines->count();
        }, attempts: 5);
    }
}
