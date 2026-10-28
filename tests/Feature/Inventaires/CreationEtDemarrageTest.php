<?php

use App\Actions\Inventories\AddAssetsToInventory;
use App\Actions\Inventories\CreateInventory;
use App\Actions\Inventories\StartInventory;
use App\Actions\Inventories\UpdateInventory;
use App\Enums\AssetStatus;
use App\Enums\InventoryLineResult;
use App\Enums\InventoryScopeType;
use App\Enums\InventoryStatus;
use App\Exceptions\ConcurrentModificationException;
use App\Models\Activity;
use App\Models\Asset;
use App\Models\Inventory;
use App\Models\InventoryLine;
use App\Models\User;
use App\Support\DefaultRoles;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;

require_once __DIR__.'/helpers.php';

beforeEach(function () {
    $this->w = inventoryWorld();
});

it('crée un inventaire planifié avec une référence INV-AAAA-NNN qui se suit (RG-44)', function () {
    $first = siegeInventory($this->w, start: false);
    $second = siegeInventory($this->w, ['scope_type' => 'department', 'scope_id' => $this->w->louange->id], start: false);

    expect($first->reference)->toBe('INV-2026-001')
        ->and($second->reference)->toBe('INV-2026-002')
        ->and($first->status)->toBe(InventoryStatus::Planned)
        ->and($first->participants)->toHaveCount(2)
        ->and($first->participants->first()->room_id)->toBe($this->w->salleCulte->id)
        ->and(InventoryLine::query()->withoutGlobalScopes()->count())->toBe(0)
        ->and($first->scopeLabel())->toBe('Siège · tout le site')
        ->and($second->scopeLabel())->toBe('Département Louange · tous sites');
});

it('fige la liste au démarrage : une ligne par bien actif du périmètre avec le snapshot attendu (RG-44)', function () {
    $inventory = siegeInventory($this->w);

    $lines = InventoryLine::query()->withoutGlobalScopes()->where('inventory_id', $inventory->id)->get();
    $line = lineOf($inventory, $this->w->bureauMeuble);

    expect($inventory->status)->toBe(InventoryStatus::InProgress)
        ->and($inventory->started_at)->not->toBeNull()
        ->and($lines->pluck('asset_id')->sort()->values()->all())->toBe(collect([
            $this->w->pupitre, $this->w->enceinte, $this->w->bureauMeuble, $this->w->pret, $this->w->maintenance, $this->w->jeune,
        ])->pluck('id')->sort()->values()->all())
        ->and($line->expected)->toBeTrue()
        ->and($line->expected_room_id)->toBe($this->w->bureau->id)
        ->and($line->expected_department_id)->toBe($this->w->louange->id)
        ->and($line->expected_condition_id)->toBe($this->w->bon->id)
        ->and($line->expected_site_id)->toBe($this->w->siege->id)
        ->and($line->result)->toBe(InventoryLineResult::Pending);
});

it('justifie d\'office les biens prêtés ou en maintenance au démarrage (RG-45)', function () {
    $inventory = siegeInventory($this->w);

    expect(lineOf($inventory, $this->w->pret)->result)->toBe(InventoryLineResult::Justified)
        ->and(lineOf($inventory, $this->w->pret)->expected_status)->toBe(AssetStatus::OnLoan)
        ->and(lineOf($inventory, $this->w->maintenance)->result)->toBe(InventoryLineResult::Justified)
        ->and(lineOf($inventory, $this->w->pupitre)->result)->toBe(InventoryLineResult::Pending);
});

it('n\'ajoute pas à la liste un bien entré dans le périmètre après le démarrage (RG-44)', function () {
    $inventory = siegeInventory($this->w);

    $this->w->annexeBien->forceFill(['site_id' => $this->w->siege->id, 'room_id' => null])->save();
    Asset::factory()->create(['site_id' => $this->w->siege->id, 'department_id' => $this->w->louange->id]);

    expect(InventoryLine::query()->withoutGlobalScopes()->where('inventory_id', $inventory->id)->count())->toBe(6)
        ->and(InventoryLine::query()->withoutGlobalScopes()->where('asset_id', $this->w->annexeBien->id)->exists())->toBeFalse();

    expect(fn () => app(StartInventory::class)($inventory, $this->w->manager))->toThrow(AuthorizationException::class);
});

it('applique chaque type de périmètre et le filtre croisé par site (RG-44)', function (string $type, string $scope, ?string $site, array $expected) {
    $w = $this->w;
    $ids = ['siege' => $w->siege->id, 'louange' => $w->louange->id, 'jeunesse' => $w->jeunesse->id, 'bureau' => $w->bureau->id, 'mobilier' => $w->mobilier->id, 'annexe' => $w->annexe->id];

    $inventory = siegeInventory($w, [
        'scope_type' => $type,
        'scope_id' => $ids[$scope] ?? null,
        'site_id' => $site === null ? null : $ids[$site],
    ]);

    $numbers = Asset::query()->withoutGlobalScopes()
        ->whereIn('id', InventoryLine::query()->withoutGlobalScopes()->where('inventory_id', $inventory->id)->select('asset_id'))
        ->orderBy('number')->pluck('number')->all();

    expect($numbers)->toBe($expected);
})->with([
    'département' => ['department', 'jeunesse', null, ['PAT-000306']],
    'local' => ['room', 'bureau', null, ['PAT-000221', 'PAT-000306']],
    'catégorie croisée avec un site' => ['category', 'mobilier', 'annexe', ['PAT-000700']],
    'tout le patrimoine' => ['all', '', null, ['PAT-000221', 'PAT-000241', 'PAT-000245', 'PAT-000306', 'PAT-000611', 'PAT-000612', 'PAT-000700']],
    'département croisé avec un site' => ['department', 'louange', 'annexe', ['PAT-000700']],
]);

it('refuse un périmètre incomplet ou des participants qui ne peuvent pas saisir', function () {
    expect(fn () => siegeInventory($this->w, ['scope_id' => null], start: false))->toThrow(ValidationException::class)
        ->and(fn () => siegeInventory($this->w, ['ends_on' => '2026-10-01'], start: false))->toThrow(ValidationException::class)
        ->and(fn () => siegeInventory($this->w, ['participants' => [['user_id' => $this->w->accountant->id]]], start: false))->toThrow(ValidationException::class);
});

it('limite la création au périmètre de l\'utilisateur (RG-81)', function () {
    $siteManager = User::factory()->role(DefaultRoles::MANAGER)->create();
    $siteManager->sites()->attach($this->w->annexe);
    $this->actingAs($siteManager);

    $data = ['scope_type' => 'site', 'scope_id' => $this->w->siege->id, 'starts_on' => '2026-11-03', 'manager_id' => $siteManager->id];

    expect(fn () => app(CreateInventory::class)($data, $siteManager))->toThrow(ValidationException::class)
        ->and(fn () => app(CreateInventory::class)([...$data, 'scope_type' => 'category', 'scope_id' => $this->w->mobilier->id], $siteManager))->toThrow(ValidationException::class);

    $own = app(CreateInventory::class)([...$data, 'scope_type' => 'category', 'scope_id' => $this->w->mobilier->id, 'site_id' => $this->w->annexe->id], $siteManager);

    expect($own->perimeter_site_id)->toBe($this->w->annexe->id);
});

it('refuse la création à qui n\'a pas le droit de créer (responsable de département)', function () {
    $this->actingAs($this->w->headLouange);

    expect(fn () => app(CreateInventory::class)([
        'scope_type' => 'department', 'scope_id' => $this->w->louange->id, 'starts_on' => '2026-11-03', 'manager_id' => $this->w->headLouange->id,
    ], $this->w->headLouange))->toThrow(AuthorizationException::class);
});

it('modifie un inventaire planifié avec contrôle de version, plus après le démarrage (RG-86)', function () {
    $inventory = siegeInventory($this->w, start: false);
    $version = $inventory->lock_version;
    $data = ['scope_type' => 'department', 'scope_id' => $this->w->louange->id, 'starts_on' => '2026-10-25', 'manager_id' => $this->w->manager->id,
        'participants' => [['user_id' => $this->w->headLouange->id]]];

    app(UpdateInventory::class)($inventory, $data, $this->w->manager, $version);

    expect($inventory->fresh()->scope_type)->toBe(InventoryScopeType::Department)
        ->and($inventory->fresh()->participants)->toHaveCount(1)
        ->and(fn () => app(UpdateInventory::class)($inventory, $data, $this->w->manager, $version))->toThrow(ConcurrentModificationException::class)
        ->and(fn () => app(UpdateInventory::class)($inventory, [...$data, 'starts_on' => '2027-01-10'], $this->w->manager))->toThrow(ValidationException::class);

    app(StartInventory::class)($inventory->fresh(), $this->w->manager);

    expect(fn () => app(UpdateInventory::class)($inventory->fresh(), $data, $this->w->manager))->toThrow(AuthorizationException::class);
});

it('ajoute des biens à un inventaire planifié, refuse un inventaire en cours (QL9-3)', function () {
    $inventory = siegeInventory($this->w, ['scope_type' => 'department', 'scope_id' => $this->w->jeunesse->id], start: false);

    expect(app(AddAssetsToInventory::class)($inventory, [$this->w->annexeBien, $this->w->jeune->id], $this->w->manager))->toBe(2);

    app(StartInventory::class)($inventory, $this->w->manager);
    $line = lineOf($inventory, $this->w->annexeBien);

    expect(InventoryLine::query()->withoutGlobalScopes()->where('inventory_id', $inventory->id)->count())->toBe(2)
        ->and($line->expected_room_id)->toBe($this->w->magasin->id)
        ->and($line->result)->toBe(InventoryLineResult::Pending)
        ->and(fn () => app(AddAssetsToInventory::class)($inventory->fresh(), [$this->w->pupitre], $this->w->manager))->toThrow(ValidationException::class);
});

it('trace la création et le démarrage au journal (RG-70)', function () {
    $inventory = siegeInventory($this->w);

    $entries = Activity::query()->where('log_name', 'inventaires')->where('subject_type', (new Inventory)->getMorphClass())->where('subject_id', $inventory->id)->pluck('event')->all();
    $started = Activity::query()->where('event', 'started')->sole();

    expect($entries)->toBe(['created', 'started'])
        ->and($started->causer_id)->toBe($this->w->manager->id)
        ->and($started->properties['attributes']['biens attendus'])->toBe(6)
        ->and($started->properties['attributes']['justifiés d\'office'])->toBe(2);
});

it('ne supprime jamais un inventaire ni une ligne', function () {
    $inventory = siegeInventory($this->w);

    expect(fn () => $inventory->delete())->toThrow(LogicException::class)
        ->and(fn () => lineOf($inventory, $this->w->pupitre)->delete())->toThrow(LogicException::class);
});
