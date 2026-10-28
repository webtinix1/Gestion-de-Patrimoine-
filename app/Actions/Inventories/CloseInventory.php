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
 * « Valider et clôturer » (E21).
 *
 * RG-48 : la clôture fige les résultats (le rapport est conservé) et ne modifie aucune fiche :
 * les régularisations se font ensuite, une par une, depuis le rapport.
 */
class CloseInventory
{
    public function __construct(private InventoryJournal $journal) {}

    public function __invoke(Inventory $inventory, User $user, ?string $comment, bool $attested): Inventory
    {
        if (! $attested) {
            throw ValidationException::withMessages(['attested' => 'Attestez que le comptage a été réalisé sur place avant de clôturer.']);
        }

        return DB::transaction(function () use ($inventory, $user, $comment): Inventory {
            // RG-47 : même verrou que la saisie : aucune saisie ne s'intercale pendant la clôture.
            $current = Inventory::query()->lockForUpdate()->findOrFail($inventory->getKey());

            if ($current->status !== InventoryStatus::ToValidate) {
                throw ValidationException::withMessages(['inventory' => $current->isClosed()
                    ? 'Cet inventaire est déjà clôturé.'
                    : 'Terminez d\'abord la saisie : seul un inventaire « à valider » se clôture.']);
            }

            if (! $user->can('close', $current)) {
                throw new AuthorizationException('Vous n\'avez pas le droit de valider et clôturer un inventaire.');
            }

            // Toutes les lignes, quel que soit le périmètre de la personne qui clôture.
            $counts = InventoryLine::query()->withoutGlobalScopes()
                ->where('inventory_id', $current->id)
                ->selectRaw('result, count(*) as total')
                ->groupBy('result')
                ->pluck('total', 'result')
                ->map(fn (mixed $total): int => (int) $total);

            $pending = $counts->get(InventoryLineResult::Pending->value, 0);

            if ($pending > 0) {
                throw ValidationException::withMessages([
                    'inventory' => $pending.' bien'.($pending > 1 ? 's' : '').' attendu'.($pending > 1 ? 's n\'ont' : ' n\'a').' pas encore de résultat : reprenez la saisie ou déclarez-les manquants avant de clôturer.',
                ]);
            }

            $comment = trim((string) $comment);

            $current->forceFill([
                'status' => InventoryStatus::Closed,
                'closed_at' => now(),
                'closed_by' => $user->id,
                'closing_comment' => $comment === '' ? null : $comment,
            ])->save();

            $summary = [];

            foreach (InventoryLineResult::cases() as $result) {
                if ($counts->get($result->value, 0) > 0) {
                    $summary[mb_strtolower($result->getLabel())] = $counts->get($result->value);
                }
            }

            $this->journal->inventory($current, 'closed', 'Validation et clôture d\'un inventaire',
                ['statut' => InventoryStatus::ToValidate->getLabel()],
                ['statut' => InventoryStatus::Closed->getLabel(), ...$summary, 'commentaire' => $current->closing_comment],
                user: $user,
            );

            $inventory->setRawAttributes($current->getAttributes(), sync: true);

            return $inventory;
        }, attempts: 5);
    }
}
