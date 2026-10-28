<?php

namespace App\Actions\Inventories;

use App\Models\Asset;
use App\Models\Inventory;
use App\Models\InventoryLine;
use App\Models\User;
use App\Services\Inventories\InventoryJournal;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * E05, action groupée « Ajouter à un inventaire » : ajoute des biens à la liste d'un inventaire
 * planifié. La liste n'est figée qu'au démarrage (RG-44) : un inventaire en cours ne reçoit plus
 * de bien (QL9-3). Le snapshot attendu de ces biens est pris au démarrage, comme pour les autres.
 */
class AddAssetsToInventory
{
    public function __construct(private InventoryJournal $journal) {}

    /**
     * @param  iterable<Asset|int>  $assets
     * @return int nombre de biens ajoutés (ceux déjà présents sont ignorés)
     */
    public function __invoke(Inventory $inventory, iterable $assets, User $user): int
    {
        $ids = collect($assets)->map(fn (Asset|int $asset): int => $asset instanceof Asset ? (int) $asset->getKey() : $asset)->unique()->sort()->values();

        if ($ids->isEmpty()) {
            throw ValidationException::withMessages(['assets' => 'Choisissez au moins un bien.']);
        }

        return DB::transaction(function () use ($inventory, $ids, $user): int {
            $current = Inventory::query()->lockForUpdate()->find($inventory->getKey());

            if (! $current instanceof Inventory) {
                throw ValidationException::withMessages(['inventory_id' => 'Inventaire introuvable.']);
            }

            if (! $current->isPlanned()) {
                throw ValidationException::withMessages(['inventory_id' => 'La liste de '.$current->reference.' est figée depuis son démarrage : on n\'y ajoute plus de bien.']);
            }

            if (! $user->can('update', $current)) {
                throw new AuthorizationException('Vous ne pouvez pas modifier cet inventaire.');
            }

            // RG-81 : seuls les biens du périmètre de l'utilisateur, encore dans le patrimoine.
            $assets = Asset::query()->whereKey($ids->all())->whereNull('disposed_at')->orderBy('id')->get();

            if ($assets->count() !== $ids->count()) {
                throw ValidationException::withMessages(['assets' => 'Certains biens sont introuvables, hors de votre périmètre ou sortis du patrimoine.']);
            }

            $existing = InventoryLine::query()->withoutGlobalScopes()->where('inventory_id', $current->id)->pluck('asset_id')->all();
            $added = $assets->reject(fn (Asset $asset): bool => in_array($asset->id, $existing, true));

            foreach ($added as $asset) {
                $line = new InventoryLine;
                $line->forceFill(['inventory_id' => $current->id, 'asset_id' => $asset->id, 'expected' => true, 'created_by' => $user->id])->save();
            }

            if ($added->isNotEmpty()) {
                $this->journal->inventory($current, 'assets_added', 'Biens ajoutés à un inventaire planifié', [], [
                    'biens' => $added->pluck('number')->join(', '),
                ], user: $user);
            }

            return $added->count();
        }, attempts: 5);
    }
}
