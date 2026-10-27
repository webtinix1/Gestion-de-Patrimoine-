<?php

use App\Actions\Assets\UpdateAsset;
use App\Actions\Movements\RecordInitialAssignment;
use App\Actions\Movements\TransferAssets;
use App\Enums\MovementType;
use App\Filament\Actions\TransferAction;
use App\Filament\Resources\Assets\Pages\ListAssets;
use App\Filament\Resources\Assets\Pages\ViewAsset;
use App\Models\Activity;
use App\Models\Asset;
use App\Models\Department;
use App\Models\Movement;
use App\Models\Room;
use App\Models\Site;
use App\Models\User;
use App\Support\DefaultRoles;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Route;
use Illuminate\Validation\ValidationException;

use function Pest\Livewire\livewire;

beforeEach(function () {
    Filament::setCurrentPanel(Filament::getPanel('admin'));

    $this->siege = Site::factory()->create(['name' => 'Siège']);
    $this->moungali = Site::factory()->create(['name' => 'Paroisse Moungali']);
    $this->louange = Department::factory()->create(['name' => 'Louange']);
    $this->jeunesse = Department::factory()->create(['name' => 'Jeunesse']);

    $this->asset = Asset::factory()->create([
        'site_id' => $this->siege->id, 'department_id' => $this->louange->id,
        'service_id' => null, 'room_id' => null, 'holder_id' => null, 'assigned_since' => '2024-01-10',
    ])->fresh();
    app(RecordInitialAssignment::class)($this->asset);

    $this->manager = User::factory()->role(DefaultRoles::MANAGER)->create();
    $this->restricted = User::factory()->role(DefaultRoles::MANAGER)->create();
    $this->restricted->departments()->attach($this->louange);
    $this->actingAs($this->manager);
});

it('refuse une destination hors du périmètre de l\'utilisateur (Q60)', function () {
    $this->actingAs($this->restricted);

    expect(fn () => app(TransferAssets::class)([$this->asset], [
        'site_id' => $this->siege->id, 'department_id' => $this->jeunesse->id, 'moved_on' => today()->toDateString(),
    ]))->toThrow(ValidationException::class, 'hors de votre périmètre');

    expect(Movement::query()->count())->toBe(1);
});

it('refuse un site hors du périmètre de l\'utilisateur (Q60)', function () {
    $user = User::factory()->role(DefaultRoles::MANAGER)->create();
    $user->sites()->attach($this->siege);
    $this->actingAs($user);

    expect(fn () => app(TransferAssets::class)([$this->asset], [
        'site_id' => $this->moungali->id, 'department_id' => $this->louange->id, 'moved_on' => today()->toDateString(),
    ]))->toThrow(ValidationException::class, 'hors de votre périmètre');
});

it('inscrit au journal les noms réels même pour un utilisateur limité (RG-70)', function () {
    $hidden = Room::factory()->create(['site_id' => $this->siege->id, 'department_id' => $this->jeunesse->id, 'name' => 'Local de la Jeunesse']);
    $asset = Asset::factory()->create([
        'site_id' => $this->siege->id, 'department_id' => $this->louange->id,
        'service_id' => null, 'room_id' => $hidden->id, 'holder_id' => null,
    ])->fresh();

    $this->actingAs($this->restricted);

    app(TransferAssets::class)([$asset], [
        'site_id' => $this->siege->id, 'department_id' => $this->louange->id, 'room_id' => null, 'moved_on' => today()->toDateString(),
    ]);

    $activity = Activity::query()->where('log_name', 'mouvements')->sole();

    expect($activity->properties->get('old'))->toBe(['room_id' => 'Local de la Jeunesse'])
        ->and($activity->properties->get('attributes')['room_id'])->toBeNull();
});

it('affiche « hors de votre périmètre » pour un élément que l\'utilisateur ne voit pas', function () {
    $hidden = Department::factory()->create(['name' => 'Jeunesse cachée']);
    $other = Asset::factory()->create(['site_id' => $this->siege->id, 'department_id' => $hidden->id, 'service_id' => null, 'room_id' => null, 'holder_id' => null])->fresh();
    app(TransferAssets::class)([$other], ['site_id' => $this->siege->id, 'department_id' => $this->louange->id, 'moved_on' => today()->toDateString()]);

    $head = User::factory()->role(DefaultRoles::DEPARTMENT_HEAD)->create();
    $head->departments()->attach($this->louange);
    $this->actingAs($head);

    $movement = Movement::query()->where('asset_id', $other->id)->latest('id')->first();

    expect($movement->assignmentLabel('from'))->toContain('(hors de votre périmètre)')->not->toContain('Jeunesse cachée')
        ->and($movement->assignmentLabel('to'))->toContain('Louange');
});

it('refuse le transfert groupé quand une fiche a changé depuis l\'ouverture de la fenêtre (RG-86)', function () {
    $second = Asset::factory()->create(['site_id' => $this->moungali->id, 'department_id' => $this->louange->id])->fresh();
    $stale = [$this->asset->id => $this->asset->lock_version, $second->id => $second->lock_version];
    $second->update(['notes' => 'Modifiée par un collègue']);

    livewire(ListAssets::class)
        ->callTableBulkAction('transfer', [$this->asset, $second], [
            'site_id' => $this->siege->id,
            'department_id' => $this->jeunesse->id,
            'moved_on' => today()->toDateString(),
            'versions' => $stale,
        ])
        ->assertNotified('Fiche modifiée entre-temps');

    expect(Movement::query()->where('type', '!=', MovementType::Initial)->count())->toBe(0)
        ->and($this->asset->fresh()->department_id)->toBe($this->louange->id);
});

it('mémorise les versions des fiches sélectionnées à l\'ouverture du transfert groupé', function () {
    livewire(ListAssets::class)
        ->mountTableBulkAction('transfer', [$this->asset])
        ->assertTableBulkActionDataSet(['versions' => [$this->asset->id => $this->asset->lock_version]]);
});

it('met la date d\'affectation à aujourd\'hui quand l\'affectation change depuis la fiche (Q61)', function () {
    app(UpdateAsset::class)($this->asset, ['department_id' => $this->jeunesse->id, 'designation' => $this->asset->designation]);

    $fresh = $this->asset->fresh();
    $movement = Movement::query()->where('asset_id', $fresh->id)->latest('id')->first();

    expect($fresh->assigned_since->toDateString())->toBe(today()->toDateString())
        ->and($movement->type)->toBe(MovementType::Department)
        ->and($movement->reason)->toBe('Modification de la fiche')
        ->and($fresh->lock_version)->toBe(1);
});

it('garde la date d\'affectation quand la modification de la fiche ne touche pas l\'affectation', function () {
    app(UpdateAsset::class)($this->asset, ['notes' => 'Une remarque']);

    expect($this->asset->fresh()->assigned_since->toDateString())->toBe('2024-01-10');
});

it('ne propose pas la réimpression ni la redirection sans page des étiquettes (RG-24)', function () {
    expect(TransferAction::labelsPageExists())->toBeFalse();

    livewire(ViewAsset::class, ['record' => $this->asset->getRouteKey()])
        ->callAction('transfer', [
            'site_id' => $this->siege->id, 'department_id' => $this->jeunesse->id,
            'moved_on' => today()->toDateString(), 'lock_version' => $this->asset->lock_version, 'reprint_label' => true,
        ])
        ->assertNoRedirect();

    expect($this->asset->fresh()->department_id)->toBe($this->jeunesse->id);
});

it('redirige vers la page des étiquettes quand elle existe (RG-24)', function () {
    Route::get('admin/etiquettes', fn () => 'ok');

    expect(TransferAction::labelsPageExists())->toBeTrue();

    livewire(ViewAsset::class, ['record' => $this->asset->getRouteKey()])
        ->callAction('transfer', [
            'site_id' => $this->siege->id, 'department_id' => $this->jeunesse->id,
            'moved_on' => today()->toDateString(), 'lock_version' => $this->asset->lock_version, 'reprint_label' => true,
        ])
        ->assertRedirect(url('/admin/etiquettes').'?biens='.$this->asset->id);
});
