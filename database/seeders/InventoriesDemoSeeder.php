<?php

namespace Database\Seeders;

use App\Actions\Inventories\CloseInventory;
use App\Actions\Inventories\CreateInventory;
use App\Actions\Inventories\RecordInventoryEntry;
use App\Actions\Inventories\RegularizeInventoryLine;
use App\Actions\Inventories\StartInventory;
use App\Actions\Inventories\SubmitInventory;
use App\Enums\InventoryLineResult;
use App\Models\Asset;
use App\Models\AssetCondition;
use App\Models\Department;
use App\Models\Inventory;
use App\Models\InventoryLine;
use App\Models\Room;
use App\Models\Site;
use App\Models\User;
use App\Services\Inventories\InventoryReferenceGenerator;
use Illuminate\Database\Seeder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Inventaires de la maquette (E18 à E21) : INV-2026-004 au Siège, clôturé avec des écarts dont
 * certains régularisés ; INV-2026-005 à l'Annexe Talangaï, en cours. Idempotent.
 * Tout passe par les actions métier, avec le compte du responsable de chaque inventaire.
 * Aucune fiche n'est créée (le prochain numéro patrimonial de la démonstration reste PAT-001249).
 */
class InventoriesDemoSeeder extends Seeder
{
    public function run(): void
    {
        $okemba = User::query()->where('username', 'a.okemba')->first();
        $ondongo = User::query()->where('username', 's.ondongo')->first();

        if (! $okemba instanceof User || ! $ondongo instanceof User || Inventory::query()->withoutGlobalScopes()->where('reference', 'like', 'INV-2026-%')->exists()) {
            return;
        }

        // Les références de la maquette commencent à INV-2026-004.
        app(InventoryReferenceGenerator::class)->syncWith(2026, 3);

        $this->closedSiegeInventory($okemba);
        $this->currentAnnexeInventory($ondongo);

        auth()->forgetUser();
    }

    private function closedSiegeInventory(User $okemba): void
    {
        auth()->setUser($okemba);
        $site = Site::query()->withoutGlobalScopes()->where('code', 'SIE')->firstOrFail();
        $participants = $this->users(['e.mbemba', 'j.nkounkou', 'r.bouanga']);

        $inventory = app(CreateInventory::class)([
            'scope_type' => 'site',
            'scope_id' => $site->id,
            'starts_on' => '2026-09-22',
            'ends_on' => '2026-09-30',
            'manager_id' => $okemba->id,
            'participants' => $participants->map(fn (User $user): array => ['user_id' => $user->id])->all(),
            'notes' => 'Inventaire annuel du Siège avec les responsables de départements.',
        ], $okemba);
        app(StartInventory::class)($inventory, $okemba);

        $record = app(RecordInventoryEntry::class);
        $lines = $this->pendingLines($inventory);
        $repair = AssetCondition::query()->where('label', 'À réparer')->value('id');
        $other = Department::query()->withoutGlobalScopes()->where('code', 'JEU')->value('id');

        $missing = $lines->slice(0, 3);
        $rest = $lines->slice(3);
        $elsewhere = $rest->filter(fn (InventoryLine $line): bool => $line->expected_department_id !== $other)->take(2);
        $damaged = $rest->diffKeys($elsewhere)->filter(fn (InventoryLine $line): bool => $line->expected_condition_id !== $repair)->take(2);
        $present = $rest->diffKeys($elsewhere)->diffKeys($damaged);

        foreach ($missing as $line) {
            $record->mark($inventory, $okemba, $line->id, 'missing', $this->version($line->id));
        }

        $record->mark($inventory, $okemba, (int) $missing->first()?->id, 'note', $this->version((int) $missing->first()?->id), [], 'Probablement emporté lors de la convention d\'août. Recherche auprès de la paroisse en cours.');

        foreach ($elsewhere as $line) {
            $record->mark($inventory, $okemba, $line->id, 'other_assignment', $this->version($line->id), ['department_id' => $other]);
        }

        foreach ($damaged as $line) {
            $record->mark($inventory, $okemba, $line->id, 'condition', $this->version($line->id), ['condition_id' => $repair], 'Ne fonctionne plus, devis de réparation demandé.');
        }

        foreach ($present as $line) {
            $record->mark($inventory, $okemba, $line->id, 'present', $this->version($line->id));
        }

        $room = Room::query()->withoutGlobalScopes()->where('code', 'SIE-CPT')->first();
        $record->addUnregistered($inventory, $okemba, [
            'description' => 'Imprimante HP LaserJet sans étiquette',
            'site_id' => $site->id,
            'department_id' => $room->department_id ?? Department::query()->withoutGlobalScopes()->where('code', 'FIN')->value('id'),
            'room_id' => $room?->id,
            'condition_id' => AssetCondition::query()->where('label', 'Bon état')->value('id'),
        ]);

        app(SubmitInventory::class)($inventory, $okemba);
        app(CloseInventory::class)($inventory, $okemba, 'Inventaire réalisé avec les responsables de départements. Manquants transmis à l\'administration pour décision.', true);

        // Régularisations depuis le rapport : un transfert, une mise à jour d'état, un bien retrouvé.
        $regularize = app(RegularizeInventoryLine::class);
        $moved = InventoryLine::query()->withoutGlobalScopes()->findOrFail($elsewhere->first()?->id);
        $asset = Asset::query()->withoutGlobalScopes()->findOrFail($moved->asset_id);
        $regularize->transfer($moved, $okemba, [
            'site_id' => $asset->site_id,
            'department_id' => $other,
            'room_id' => $asset->room_id,
            'holder_id' => $asset->holder_id,
            'moved_on' => today()->toDateString(),
        ], $asset->lock_version);
        $damagedLine = InventoryLine::query()->withoutGlobalScopes()->findOrFail($damaged->first()?->id);
        $regularize->updateCondition($damagedLine, $okemba, (int) Asset::query()->withoutGlobalScopes()->whereKey($damagedLine->asset_id)->value('lock_version'));
        $regularize->markFound(InventoryLine::query()->withoutGlobalScopes()->findOrFail($missing->last()?->id), $okemba, 'Retrouvé le 03/10 au magasin central.');

        // Dates de la maquette (22/09 au 30/09), sans passer par les modèles (inventaire clôturé, figé).
        DB::table('inventories')->where('id', $inventory->id)->update([
            'started_at' => '2026-09-22 08:30:00', 'submitted_at' => '2026-09-30 16:00:00', 'closed_at' => '2026-09-30 17:00:00',
        ]);
        DB::table('inventory_lines')->where('inventory_id', $inventory->id)->whereNotNull('checked_at')->update(['checked_at' => '2026-09-25 10:00:00']);
    }

    private function currentAnnexeInventory(User $ondongo): void
    {
        auth()->setUser($ondongo);
        $site = Site::query()->withoutGlobalScopes()->where('code', 'TAL')->firstOrFail();
        $participants = $this->users(['d.massamba', 'p.itoua', 'd.kibangou']);

        $inventory = app(CreateInventory::class)([
            'scope_type' => 'site',
            'scope_id' => $site->id,
            'starts_on' => '2026-10-24',
            'ends_on' => '2026-10-28',
            'manager_id' => $ondongo->id,
            'participants' => $participants->map(fn (User $user): array => [
                'user_id' => $user->id,
                'room_id' => Room::query()->withoutGlobalScopes()->where('code', ['d.massamba' => 'TAL-CUL', 'p.itoua' => 'TAL-BUR', 'd.kibangou' => 'TAL-MAG'][$user->username])->value('id'),
            ])->all(),
            'notes' => 'Inventaire de l\'annexe, trois équipes.',
        ], $ondongo);
        app(StartInventory::class)($inventory, $ondongo);

        $record = app(RecordInventoryEntry::class);
        $lines = $this->pendingLines($inventory);
        $checked = (int) floor($lines->count() * 2 / 3);

        foreach ($lines->take($checked) as $index => $line) {
            $index < 2
                ? $record->mark($inventory, $ondongo, $line->id, 'missing', $this->version($line->id))
                : $record->mark($inventory, $ondongo, $line->id, 'present', $this->version($line->id));
        }

        if (($damaged = $lines->get($checked - 1)) !== null) {
            $record->mark($inventory, $ondongo, $damaged->id, 'condition', $this->version($damaged->id), ['condition_id' => AssetCondition::query()->where('label', 'À réparer')->value('id')]);
        }

        $room = Room::query()->withoutGlobalScopes()->where('code', 'TAL-CUL')->first();
        $record->addUnregistered($inventory, $ondongo, [
            'description' => 'Ventilateur sur pied Binatone',
            'site_id' => $site->id,
            'department_id' => Department::query()->withoutGlobalScopes()->where('code', 'LOG')->value('id'),
            'room_id' => $room?->id,
            'condition_id' => AssetCondition::query()->where('label', 'Bon état')->value('id'),
        ]);

        DB::table('inventories')->where('id', $inventory->id)->update(['started_at' => '2026-10-24 08:30:00']);
    }

    /**
     * @return Collection<int, InventoryLine>
     */
    private function pendingLines(Inventory $inventory): Collection
    {
        return InventoryLine::query()->withoutGlobalScopes()
            ->where('inventory_id', $inventory->id)
            ->where('result', InventoryLineResult::Pending)
            ->orderBy('asset_id')
            ->get()
            ->values();
    }

    /** RG-47 : la version réelle de la ligne, comme l'écran la transmet. */
    private function version(int $lineId): int
    {
        return (int) InventoryLine::query()->withoutGlobalScopes()->whereKey($lineId)->value('lock_version');
    }

    /**
     * @param  list<string>  $usernames
     * @return Collection<int, User>
     */
    private function users(array $usernames): Collection
    {
        return User::query()->whereIn('username', $usernames)->where('is_active', true)->orderBy('id')->get();
    }
}
