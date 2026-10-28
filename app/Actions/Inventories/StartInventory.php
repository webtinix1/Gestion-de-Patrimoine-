<?php

namespace App\Actions\Inventories;

use App\Enums\AssetStatus;
use App\Enums\InventoryLineResult;
use App\Enums\InventoryStatus;
use App\Models\Asset;
use App\Models\Inventory;
use App\Models\InventoryLine;
use App\Models\User;
use App\Services\Inventories\InventoryJournal;
use App\Services\Inventories\InventoryScope;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Démarre un inventaire (E18, A Démarrer).
 *
 * - RG-44 : la liste des biens attendus est figée : une ligne par bien actif du périmètre, avec le
 *   snapshot attendu (site, local, département, état, statut). Un bien ajouté au périmètre ensuite n'y entre pas.
 * - RG-45 : un bien prêté ou en maintenance au démarrage est « justifié » d'office.
 */
class StartInventory
{
    /** RG-45 : statuts justifiés au démarrage. */
    public const array JUSTIFIED_STATUSES = [AssetStatus::OnLoan, AssetStatus::InMaintenance];

    public function __construct(
        private InventoryScope $scope,
        private InventoryJournal $journal,
    ) {}

    public function __invoke(Inventory $inventory, User $user): Inventory
    {
        return DB::transaction(function () use ($inventory, $user): Inventory {
            $current = Inventory::query()->lockForUpdate()->find($inventory->getKey());

            if (! $current instanceof Inventory || ! $user->can('start', $current)) {
                throw new AuthorizationException('Vous ne pouvez pas démarrer cet inventaire.');
            }

            if ($current->status !== InventoryStatus::Planned) {
                throw ValidationException::withMessages(['inventory' => 'Cet inventaire est déjà démarré : sa liste est figée.']);
            }

            $now = now();
            $justified = array_map(fn (AssetStatus $status): string => $status->value, self::JUSTIFIED_STATUSES);
            $manual = InventoryLine::query()->withoutGlobalScopes()->where('inventory_id', $current->id)->pluck('asset_id')->filter()->all();

            $ids = $this->scope->assetsOf($current)->pluck('id')->merge($manual)->unique()->sort()->values();
            $count = 0;
            $justifiedCount = 0;

            foreach ($ids->chunk(300) as $chunk) {
                $assets = Asset::query()->withoutGlobalScopes()->whereKey($chunk->all())->orderBy('id')->get();
                $rows = [];

                foreach ($assets as $asset) {
                    $isActive = $asset->disposed_at === null && $asset->status->isInActivePatrimony();

                    if (! $isActive) {
                        continue;
                    }

                    $isJustified = in_array($asset->status->value, $justified, true);
                    $snapshot = [
                        'expected_site_id' => $asset->site_id,
                        'expected_room_id' => $asset->room_id,
                        'expected_department_id' => $asset->department_id,
                        'expected_condition_id' => $asset->asset_condition_id,
                        'expected_status' => $asset->status->value,
                        'result' => $isJustified ? InventoryLineResult::Justified->value : InventoryLineResult::Pending->value,
                        'checked_at' => $isJustified ? $now : null,
                        'updated_at' => $now,
                    ];

                    $count++;
                    $justifiedCount += $isJustified ? 1 : 0;

                    if (in_array($asset->id, $manual, true)) {
                        DB::table('inventory_lines')->where('inventory_id', $current->id)->where('asset_id', $asset->id)->update($snapshot);

                        continue;
                    }

                    $rows[] = [...$snapshot, 'inventory_id' => $current->id, 'asset_id' => $asset->id, 'expected' => true,
                        'lock_version' => 0, 'created_by' => $user->id, 'created_at' => $now];
                }

                if ($rows !== []) {
                    DB::table('inventory_lines')->insert($rows);
                }
            }

            // Un bien ajouté à la main puis sorti du patrimoine avant le démarrage n'est plus attendu.
            DB::table('inventory_lines')->where('inventory_id', $current->id)->whereNull('expected_status')->whereNotNull('asset_id')->delete();

            $current->forceFill(['status' => InventoryStatus::InProgress, 'started_at' => $now, 'started_by' => $user->id])->save();

            $this->journal->inventory($current, 'started', 'Démarrage d\'un inventaire',
                ['statut' => InventoryStatus::Planned->getLabel()],
                ['statut' => InventoryStatus::InProgress->getLabel(), 'biens attendus' => $count, 'justifiés d\'office' => $justifiedCount],
                user: $user,
            );

            $inventory->setRawAttributes($current->getAttributes(), sync: true);

            return $inventory;
        }, attempts: 5);
    }
}
