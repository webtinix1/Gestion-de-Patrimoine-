<?php

use App\Actions\Acquisitions\CreateAcquisition;
use App\Filament\Resources\Acquisitions\Pages\ListAcquisitions;
use App\Filament\Resources\Acquisitions\Pages\ViewAcquisition;
use App\Models\Acquisition;
use App\Models\AcquisitionLine;
use App\Models\AssetCategory;
use App\Models\Department;
use App\Models\Site;
use App\Models\Supplier;
use App\Models\User;
use App\Support\DefaultRoles;
use Filament\Facades\Filament;
use Illuminate\Validation\ValidationException;

use function Pest\Livewire\livewire;

beforeEach(function () {
    Filament::setCurrentPanel(Filament::getPanel('admin'));

    $this->siteA = Site::factory()->create(['name' => 'Siège']);
    $this->siteB = Site::factory()->create(['name' => 'Moungali']);
    $this->louange = Department::factory()->create(['name' => 'Louange']);
    $this->jeunesse = Department::factory()->create(['name' => 'Jeunesse']);

    $this->own = Acquisition::factory()->create(['site_id' => $this->siteA->id, 'department_id' => $this->louange->id]);
    $this->other = Acquisition::factory()->create(['site_id' => $this->siteB->id, 'department_id' => $this->jeunesse->id]);
});

it('laisse le gestionnaire consulter, créer et modifier mais pas valider (matrice RG-80)', function () {
    $manager = User::factory()->role(DefaultRoles::MANAGER)->create();

    expect($manager->can('viewAny', Acquisition::class))->toBeTrue()
        ->and($manager->can('create', Acquisition::class))->toBeTrue()
        ->and($manager->can('update', $this->own))->toBeTrue()
        ->and($manager->can('validate', $this->own))->toBeFalse();

    $this->actingAs($manager);

    livewire(ViewAcquisition::class, ['record' => $this->own->getRouteKey()])->assertActionHidden('validate');
});

it('laisse l\'administrateur valider', function () {
    $admin = User::factory()->admin()->create();
    $line = AcquisitionLine::factory()->create(['acquisition_id' => $this->own->id, 'asset_category_id' => AssetCategory::factory()]);

    expect($admin->can('validate', $this->own))->toBeTrue();
});

it('limite le comptable et la consultation à la lecture', function (string $role) {
    $user = User::factory()->role($role)->create();

    expect($user->can('viewAny', Acquisition::class))->toBeTrue()
        ->and($user->can('create', Acquisition::class))->toBeFalse()
        ->and($user->can('update', $this->own))->toBeFalse()
        ->and($user->can('validate', $this->own))->toBeFalse()
        ->and($user->can('delete', $this->own))->toBeFalse();
})->with([DefaultRoles::ACCOUNTANT, DefaultRoles::VIEWER]);

it('ferme le module au responsable de département', function () {
    $this->actingAs(User::factory()->role(DefaultRoles::DEPARTMENT_HEAD)->create());

    $this->get('/admin/acquisitions')->assertForbidden();
});

it('ne montre que les acquisitions du périmètre (RG-81)', function () {
    $user = User::factory()->role(DefaultRoles::MANAGER)->create();
    $user->departments()->attach($this->louange);
    $this->actingAs($user);

    livewire(ListAcquisitions::class)
        ->assertCanSeeTableRecords([$this->own])
        ->assertCanNotSeeTableRecords([$this->other]);

    $this->get("/admin/acquisitions/{$this->own->id}")->assertOk();
    $this->get("/admin/acquisitions/{$this->other->id}")->assertNotFound();
});

it('filtre aussi par site', function () {
    $user = User::factory()->role(DefaultRoles::MANAGER)->create();
    $user->sites()->attach($this->siteB);
    $this->actingAs($user);

    livewire(ListAcquisitions::class)
        ->assertCanSeeTableRecords([$this->other])
        ->assertCanNotSeeTableRecords([$this->own]);
});

it('refuse de créer une acquisition hors du périmètre', function () {
    $user = User::factory()->role(DefaultRoles::MANAGER)->create();
    $user->departments()->attach($this->louange);
    $this->actingAs($user);

    $data = [
        'acquired_on' => '2026-10-26',
        'supplier_id' => Supplier::factory()->create()->id,
        'site_id' => $this->siteA->id,
        'department_id' => $this->jeunesse->id,
        'lines' => [['designation' => 'X', 'asset_category_id' => AssetCategory::factory()->create()->id, 'quantity' => 1, 'unit_price' => 1000, 'tracked_individually' => true]],
    ];

    expect(fn () => app(CreateAcquisition::class)($data))->toThrow(ValidationException::class)
        ->and(app(CreateAcquisition::class)([...$data, 'department_id' => $this->louange->id])->department_id)->toBe($this->louange->id);
});

it('filtre la liste par financement, période et recherche', function () {
    $this->actingAs(User::factory()->admin()->create());
    $grant = Acquisition::factory()->create(['funding_source' => 'grant', 'acquired_on' => '2025-03-10', 'invoice_reference' => 'ZZZ-77']);

    livewire(ListAcquisitions::class)
        ->filterTable('funding_source', 'grant')
        ->assertCanSeeTableRecords([$grant])
        ->assertCanNotSeeTableRecords([$this->own])
        ->removeTableFilters()
        ->filterTable('year', '2025')
        ->assertCanSeeTableRecords([$grant])
        ->assertCanNotSeeTableRecords([$this->own])
        ->removeTableFilters()
        ->searchTable('ZZZ-77')
        ->assertCanSeeTableRecords([$grant])
        ->assertCanNotSeeTableRecords([$this->own, $this->other]);
});
