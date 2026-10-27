<?php

use App\Actions\Assets\CreateAsset as CreateAssetAction;
use App\Enums\AssetStatus;
use App\Enums\Depreciability;
use App\Filament\Resources\Assets\Pages\CreateAsset;
use App\Filament\Resources\Assets\Pages\EditAsset;
use App\Filament\Resources\Assets\Pages\ListAssets;
use App\Filament\Resources\Assets\Pages\ViewAsset;
use App\Models\Activity;
use App\Models\Asset;
use App\Models\AssetCategory;
use App\Models\AssetCondition;
use App\Models\Department;
use App\Models\Site;
use App\Models\User;
use App\Support\DefaultRoles;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Facades\Filament;
use Illuminate\Validation\ValidationException;

use function Pest\Livewire\livewire;

beforeEach(function () {
    $this->user = User::factory()->role(DefaultRoles::MANAGER)->create();
    $this->actingAs($this->user);
    Filament::setCurrentPanel(Filament::getPanel('admin'));

    $this->site = Site::factory()->create();
    $this->department = Department::factory()->create();
    $this->condition = AssetCondition::factory()->create(['label' => 'Neuf']);
    $this->computers = AssetCategory::factory()->create([
        'name' => 'Matériel informatique', 'prefix' => 'INF', 'depreciable' => Depreciability::Yes,
        'default_duration_years' => 3, 'asset_account' => '2441', 'depreciation_account' => '28441',
    ]);
});

function validAssetData(array $overrides = []): array
{
    return [
        'designation' => 'Ordinateur portable',
        'asset_category_id' => test()->computers->id,
        'brand' => 'HP',
        'model' => 'EliteBook 840',
        'serial_number' => 'SN-001',
        'acquired_on' => '2024-03-18',
        'in_service_on' => '2024-03-18',
        'acquisition_cost' => 540000,
        'site_id' => test()->site->id,
        'department_id' => test()->department->id,
        'asset_condition_id' => test()->condition->id,
        'status' => AssetStatus::InService->value,
        ...$overrides,
    ];
}

it('enregistre un bien amortissable avec les règles de sa catégorie (RG-10, RG-31)', function () {
    livewire(CreateAsset::class)
        ->fillForm(validAssetData())
        ->call('create')
        ->assertHasNoFormErrors();

    expect(Asset::query()->sole())
        ->number->toBe('PAT-000001')
        ->is_depreciable->toBeTrue()
        ->duration_years->toBe(3)
        ->asset_account->toBe('2441')
        ->depreciation_account->toBe('28441')
        ->created_by->toBe($this->user->id);

    expect(Activity::query()->where('log_name', 'biens')->sole()->description)->toBe('Création d\'un bien');
});

it('exige les champs obligatoires (RG-10)', function () {
    livewire(CreateAsset::class)
        ->fillForm(['designation' => '', 'asset_category_id' => null, 'acquired_on' => null, 'site_id' => null,
            'department_id' => null, 'asset_condition_id' => null, 'status' => null])
        ->call('create')
        ->assertHasFormErrors([
            'designation' => 'required', 'asset_category_id' => 'required', 'acquired_on' => 'required',
            'site_id' => 'required', 'department_id' => 'required', 'asset_condition_id' => 'required', 'status' => 'required',
        ]);
});

it('exige le prix pour un bien amortissable, pas pour un bien non amortissable', function () {
    livewire(CreateAsset::class)
        ->fillForm(validAssetData(['acquisition_cost' => null]))
        ->call('create')
        ->assertHasFormErrors(['acquisition_cost' => 'required']);

    $other = AssetCategory::factory()->create(['prefix' => 'AUT', 'depreciable' => Depreciability::No]);

    livewire(CreateAsset::class)
        ->fillForm(validAssetData(['asset_category_id' => $other->id, 'acquisition_cost' => null, 'serial_number' => null]))
        ->call('create')
        ->assertHasNoFormErrors();
});

it('applique le seuil d\'immobilisation pour une catégorie « selon seuil » (RG-30)', function (int $cost, bool $depreciable) {
    $office = AssetCategory::factory()->create(['prefix' => 'BUR', 'depreciable' => Depreciability::ThresholdBased, 'default_duration_years' => 5]);

    livewire(CreateAsset::class)
        ->fillForm(validAssetData(['asset_category_id' => $office->id, 'acquisition_cost' => $cost]))
        ->call('create')
        ->assertHasNoFormErrors();

    expect(Asset::query()->sole()->is_depreciable)->toBe($depreciable);
})->with([
    'sous le seuil' => [80000, false],
    'au seuil' => [100000, true],
]);

it('permet de forcer « non amortissable » (RG-30)', function () {
    livewire(CreateAsset::class)
        ->fillForm(validAssetData(['depreciation_forced_off' => true]))
        ->call('create')
        ->assertHasNoFormErrors();

    expect(Asset::query()->sole())
        ->is_depreciable->toBeFalse()
        ->duration_years->toBeNull();
});

it('ne propose à la main que En service, En stock et Hors service (RG-12)', function () {
    livewire(CreateAsset::class)
        ->fillForm(validAssetData(['status' => AssetStatus::OnLoan->value]))
        ->call('create')
        ->assertHasFormErrors(['status']);

    expect(Asset::query()->count())->toBe(0);
});

it('refuse un numéro de série déjà porté par un bien actif, en citant ce bien (RG-13)', function () {
    $existing = Asset::factory()->create(['serial_number' => 'SN-001', 'number' => 'PAT-000125']);

    livewire(CreateAsset::class)
        ->fillForm(validAssetData())
        ->call('create')
        ->assertHasFormErrors(['serial_number']);

    $existing->forceFill(['disposed_at' => now(), 'status' => AssetStatus::Retired])->save();

    livewire(CreateAsset::class)
        ->fillForm(validAssetData())
        ->call('create')
        ->assertHasNoFormErrors();
});

it('modifie une fiche et trace avant et après (RG-70)', function () {
    $asset = Asset::factory()->create(['designation' => 'Chaise', 'acquisition_cost' => 25000, 'asset_category_id' => AssetCategory::factory()->create(['depreciable' => 'no'])]);

    livewire(EditAsset::class, ['record' => $asset->getRouteKey()])
        ->fillForm(['designation' => 'Chaise pliante'])
        ->call('save')
        ->assertHasNoFormErrors();

    $activity = Activity::query()->where('subject_id', $asset->id)->where('event', 'updated')->latest('id')->first();

    expect($asset->refresh()->designation)->toBe('Chaise pliante')
        ->and($activity->description)->toBe('Modification d\'une fiche')
        ->and($activity->properties->get('old')['designation'])->toBe('Chaise')
        ->and($activity->properties->get('attributes')['designation'])->toBe('Chaise pliante');
});

it('prévient le second utilisateur qui enregistre une fiche modifiée entre-temps (RG-86)', function () {
    $asset = Asset::factory()->create(['designation' => 'Chaise', 'asset_category_id' => AssetCategory::factory()->create(['depreciable' => 'no'])]);

    $page = livewire(EditAsset::class, ['record' => $asset->getRouteKey()]);

    // Même seconde que l'ouverture : seule la version de la fiche permet de le voir.
    $this->freezeTime();
    $other = Asset::query()->find($asset->id);
    $other->update(['designation' => 'Chaise de l\'autre poste']);

    $page->fillForm(['designation' => 'Ma version'])
        ->call('save')
        ->assertNotified('Cette fiche a été modifiée entre-temps');

    expect($asset->refresh()->designation)->toBe('Chaise de l\'autre poste');
});

it('incrémente la version de la fiche à chaque enregistrement (RG-86)', function () {
    $asset = Asset::factory()->create(['asset_category_id' => AssetCategory::factory()->create(['depreciable' => 'no'])]);
    $version = $asset->lock_version;

    $asset->update(['notes' => 'Vérifié']);

    expect($asset->refresh()->lock_version)->toBe($version + 1);
});

it('refuse un doublon de numéro de série même hors de l\'écran (RG-13)', function () {
    Asset::factory()->create(['serial_number' => 'SN-001', 'number' => 'PAT-000125']);

    expect(fn () => app(CreateAssetAction::class)(validAssetData()))
        ->toThrow(ValidationException::class, 'PAT-000125');
});

it('inscrit le numéro patrimonial au journal de création', function () {
    $asset = app(CreateAssetAction::class)(validAssetData());

    $activity = Activity::query()->whereMorphedTo('subject', $asset)->where('event', 'created')->sole();

    expect($activity->properties->get('attributes')['number'])->toBe($asset->number);
});

it('présente la fiche d\'un bien sorti en lecture seule (E43)', function () {
    $asset = Asset::factory()->disposed()->create();

    livewire(ViewAsset::class, ['record' => $asset->getRouteKey()])
        ->assertSee('Sorti du patrimoine')
        ->assertActionHidden(EditAction::class);

    $this->get("/admin/biens/{$asset->id}/edit")->assertForbidden();
});

it('ne propose jamais la suppression d\'un bien', function () {
    $asset = Asset::factory()->create();

    livewire(ViewAsset::class, ['record' => $asset->getRouteKey()])->assertActionDoesNotExist(DeleteAction::class);
    expect($this->user->can('delete', $asset))->toBeFalse();
});

it('masque par défaut les biens sortis et filtre la liste (E05)', function () {
    $active = Asset::factory()->create(['site_id' => $this->site->id]);
    $other = Asset::factory()->create();
    $disposed = Asset::factory()->disposed()->create();

    livewire(ListAssets::class)
        ->assertCanSeeTableRecords([$active, $other])
        ->assertCanNotSeeTableRecords([$disposed])
        ->filterTable('site_id', $this->site->id)
        ->assertCanSeeTableRecords([$active])
        ->assertCanNotSeeTableRecords([$other]);
});

it('cherche par numéro, désignation et numéro de série', function () {
    $laptop = Asset::factory()->create(['number' => 'PAT-000125', 'designation' => 'Ordinateur', 'serial_number' => 'X5LK2300871']);
    $chair = Asset::factory()->create(['number' => 'PAT-000126', 'designation' => 'Chaise']);

    livewire(ListAssets::class)
        ->searchTable('X5LK')
        ->assertCanSeeTableRecords([$laptop])
        ->assertCanNotSeeTableRecords([$chair])
        ->searchTable('PAT-000126')
        ->assertCanSeeTableRecords([$chair]);
});

it('interdit la création au responsable de département (RG-82)', function () {
    $this->actingAs(User::factory()->role(DefaultRoles::DEPARTMENT_HEAD)->create())
        ->get('/admin/biens/create')
        ->assertForbidden();
});
