<?php

use App\Actions\Inventories\CreateInventory;
use App\Actions\Inventories\StartInventory;
use App\Enums\AssetStatus;
use App\Enums\Depreciability;
use App\Enums\InventoryScopeType;
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
use Filament\Facades\Filament;

/**
 * Jeu de données commun aux tests d'inventaire : deux sites, deux départements, trois locaux,
 * des biens au Siège (Louange et Jeunesse), à l'Annexe, un prêté, un en maintenance, un sorti.
 */
function inventoryWorld(): object
{
    Filament::setCurrentPanel(Filament::getPanel('admin'));

    $w = new stdClass;
    $w->siege = Site::factory()->create(['name' => 'Siège', 'code' => 'SIE']);
    $w->annexe = Site::factory()->create(['name' => 'Annexe Talangaï', 'code' => 'TAL']);
    $w->louange = Department::factory()->create(['name' => 'Louange', 'code' => 'LOU']);
    $w->jeunesse = Department::factory()->create(['name' => 'Jeunesse', 'code' => 'JEU']);
    $w->salleCulte = Room::factory()->create(['site_id' => $w->siege->id, 'name' => 'Salle de culte', 'code' => 'SIE-CUL']);
    $w->bureau = Room::factory()->create(['site_id' => $w->siege->id, 'name' => 'Bureau du responsable', 'code' => 'SIE-BUR']);
    $w->magasin = Room::factory()->create(['site_id' => $w->annexe->id, 'name' => 'Magasin', 'code' => 'TAL-MAG']);
    $w->bon = AssetCondition::factory()->create(['label' => 'Bon état', 'sort' => 1]);
    $w->aReparer = AssetCondition::factory()->create(['label' => 'À réparer', 'sort' => 2]);
    $w->mobilier = AssetCategory::factory()->create(['name' => 'Mobilier', 'prefix' => 'MOB', 'depreciable' => Depreciability::No]);

    $asset = fn (string $number, array $attributes = []): Asset => Asset::factory()->create([
        'number' => $number,
        'designation' => 'Bien '.$number,
        'asset_category_id' => $w->mobilier->id,
        'site_id' => $w->siege->id,
        'department_id' => $w->louange->id,
        'room_id' => $w->salleCulte->id,
        'asset_condition_id' => $w->bon->id,
        'status' => AssetStatus::InService,
        ...$attributes,
    ])->fresh();

    $w->pupitre = $asset('PAT-000241');
    $w->enceinte = $asset('PAT-000245');
    $w->bureauMeuble = $asset('PAT-000221', ['room_id' => $w->bureau->id]);
    $w->pret = $asset('PAT-000611', ['status' => AssetStatus::OnLoan]);
    $w->maintenance = $asset('PAT-000612', ['status' => AssetStatus::InMaintenance]);
    $w->jeune = $asset('PAT-000306', ['department_id' => $w->jeunesse->id, 'room_id' => $w->bureau->id]);
    $w->annexeBien = $asset('PAT-000700', ['site_id' => $w->annexe->id, 'room_id' => $w->magasin->id]);
    $w->sorti = Asset::factory()->disposed()->create(['number' => 'PAT-000099', 'site_id' => $w->siege->id, 'department_id' => $w->louange->id, 'asset_condition_id' => $w->bon->id, 'asset_category_id' => $w->mobilier->id]);

    $w->manager = User::factory()->role(DefaultRoles::MANAGER)->create(['first_name' => 'Albertine', 'last_name' => 'Okemba']);
    $w->admin = User::factory()->admin()->create();
    $w->accountant = User::factory()->role(DefaultRoles::ACCOUNTANT)->create();
    $w->headLouange = User::factory()->role(DefaultRoles::DEPARTMENT_HEAD)->create(['first_name' => 'Emmanuel', 'last_name' => 'Mbemba']);
    $w->headLouange->departments()->attach($w->louange);
    $w->headJeunesse = User::factory()->role(DefaultRoles::DEPARTMENT_HEAD)->create(['first_name' => 'David', 'last_name' => 'Massamba']);
    $w->headJeunesse->departments()->attach($w->jeunesse);

    return $w;
}

/**
 * Inventaire du Siège (tout le site), participants : les deux responsables de département.
 *
 * @param  array<string, mixed>  $overrides
 */
function siegeInventory(object $w, array $overrides = [], bool $start = true): Inventory
{
    test()->actingAs($w->manager);

    $inventory = app(CreateInventory::class)([
        'scope_type' => InventoryScopeType::Site->value,
        'scope_id' => $w->siege->id,
        'starts_on' => '2026-10-24',
        'ends_on' => '2026-10-28',
        'manager_id' => $w->manager->id,
        'participants' => [
            ['user_id' => $w->headLouange->id, 'room_id' => $w->salleCulte->id],
            ['user_id' => $w->headJeunesse->id, 'room_id' => null],
        ],
        'notes' => 'Inventaire annuel.',
        ...$overrides,
    ], $w->manager);

    if ($start) {
        app(StartInventory::class)($inventory, $w->manager);
    }

    return $inventory->fresh();
}

function lineOf(Inventory $inventory, Asset $asset): InventoryLine
{
    return InventoryLine::query()->withoutGlobalScopes()->where('inventory_id', $inventory->id)->where('asset_id', $asset->id)->firstOrFail();
}
