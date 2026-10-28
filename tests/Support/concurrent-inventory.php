<?php

/*
 * Processus lancé en parallèle par SaisieSimultaneeConcurrenceTest (RG-47).
 * Usage : php tests/Support/concurrent-inventory.php setup|scan|mark|report <arguments>
 */

use App\Actions\Inventories\CreateInventory;
use App\Actions\Inventories\RecordInventoryEntry;
use App\Actions\Inventories\StartInventory;
use App\Enums\AssetStatus;
use App\Enums\Depreciability;
use App\Exceptions\InventoryLineConflictException;
use App\Models\Activity;
use App\Models\Asset;
use App\Models\AssetCategory;
use App\Models\AssetCondition;
use App\Models\Department;
use App\Models\Inventory;
use App\Models\InventoryLine;
use App\Models\Room;
use App\Models\Site;
use App\Models\User;
use App\Support\DefaultRoles;
use Database\Seeders\RolesSeeder;
use Illuminate\Contracts\Console\Kernel;

require __DIR__.'/../../vendor/autoload.php';

$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$mode = $argv[1] ?? 'report';
$args = array_slice($argv, 2);

$user = fn (int $index): User => User::query()->where('username', 'poste'.$index)->firstOrFail();

switch ($mode) {
    case 'setup': // setup <nombre de biens> : crée les biens, 6 comptes et démarre l'inventaire
        (new RolesSeeder)->run();
        $site = Site::query()->create(['name' => 'Siège', 'code' => 'SIE']);
        $department = Department::query()->create(['name' => 'Louange', 'code' => 'LOU']);
        $category = AssetCategory::query()->create(['name' => 'Mobilier', 'prefix' => 'MOB', 'depreciable' => Depreciability::No]);
        $condition = AssetCondition::query()->create(['label' => 'Bon état', 'sort' => 1]);
        $room = Room::query()->create(['site_id' => $site->id, 'name' => 'Salle de culte', 'code' => 'SIE-CUL']);
        $other = Site::query()->create(['name' => 'Annexe', 'code' => 'TAL']);

        foreach (range(0, 6) as $index) {
            $account = User::query()->create([
                'first_name' => 'Poste', 'last_name' => (string) $index, 'username' => 'poste'.$index,
                'password' => 'Patrimoine2026', 'is_active' => true, 'must_change_password' => false,
            ]);
            $account->assignRole($index === 0 ? DefaultRoles::MANAGER : DefaultRoles::DEPARTMENT_HEAD);
        }

        $make = function (string $number, int $siteId) use ($department, $category, $condition, $room): void {
            $asset = new Asset([
                'designation' => 'Chaise '.$number, 'asset_category_id' => $category->id, 'acquired_on' => '2026-01-10',
                'site_id' => $siteId, 'department_id' => $department->id, 'room_id' => $siteId === $room->site_id ? $room->id : null,
                'asset_condition_id' => $condition->id, 'status' => AssetStatus::InService,
            ]);
            $asset->forceFill(['number' => $number])->save();
        };

        foreach (range(1, (int) ($args[0] ?? 30)) as $n) {
            $make(sprintf('PAT-%06d', $n), $site->id);
        }

        $make('PAT-900001', $other->id);

        $manager = $user(0);
        auth()->setUser($manager);
        $inventory = app(CreateInventory::class)([
            'scope_type' => 'site', 'scope_id' => $site->id, 'starts_on' => '2026-10-24', 'manager_id' => $manager->id,
            'participants' => array_map(fn (int $i): array => ['user_id' => $user($i)->id], range(1, 6)),
        ], $manager);
        app(StartInventory::class)($inventory, $manager);

        echo $inventory->id.PHP_EOL;
        break;

    case 'scan': // scan <inventaire> <poste> : lit tous les biens dans un ordre aléatoire, plus un bien hors liste
        $account = $user((int) $args[1]);
        auth()->setUser($account);
        $inventory = Inventory::query()->findOrFail((int) $args[0]);
        $numbers = Asset::query()->withoutGlobalScopes()->orderBy('number')->pluck('number')->all();
        shuffle($numbers);

        foreach ($numbers as $number) {
            $outcome = app(RecordInventoryEntry::class)->scan($inventory, $account, $number, Room::query()->value('id'));
            echo $outcome->status.' '.$number.PHP_EOL;
        }
        break;

    case 'mark': // mark <inventaire> <poste> <ligne> <version> <saisie> : bouton appuyé sur la ligne vue à cette version
        $account = $user((int) $args[1]);
        auth()->setUser($account);
        $inventory = Inventory::query()->findOrFail((int) $args[0]);
        usleep(random_int(0, 3000));

        try {
            app(RecordInventoryEntry::class)->mark($inventory, $account, (int) $args[2], $args[4], (int) $args[3]);
            echo 'OK '.$args[4].PHP_EOL;
        } catch (InventoryLineConflictException) {
            echo 'CONFLIT'.PHP_EOL;
        }
        break;

    case 'line': // line <inventaire> <numéro> : identifiant et version de la ligne d'un bien
        $line = InventoryLine::query()->withoutGlobalScopes()->where('inventory_id', (int) $args[0])
            ->where('asset_id', Asset::query()->withoutGlobalScopes()->where('number', $args[1])->value('id'))->firstOrFail();
        echo $line->id.' '.$line->lock_version.PHP_EOL;
        break;

    case 'report': // report <inventaire> : résultats, lignes par bien, entrées de journal
        $lines = InventoryLine::query()->withoutGlobalScopes()->where('inventory_id', (int) $args[0])->get();
        echo json_encode([
            'lines' => $lines->count(),
            'max_per_asset' => $lines->groupBy('asset_id')->map->count()->max(),
            'results' => $lines->countBy(fn (InventoryLine $line): string => $line->result->value)->all(),
            'journal' => Activity::query()->where('event', 'inventory_line_recorded')->count(),
            'journal_by_line' => Activity::query()->where('event', 'inventory_line_recorded')->get()->countBy(fn (Activity $a): string => (string) $a->properties['line_id'])->max(),
        ]).PHP_EOL;
        break;
}
