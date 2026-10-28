<?php

use App\Actions\Movements\RecordInitialAssignment;
use App\Actions\Movements\TransferAssets;
use App\Enums\MovementType;
use App\Filament\Actions\TransferAction;
use App\Filament\Resources\Assets\Pages\ListAssets;
use App\Filament\Resources\Assets\Pages\ViewAsset;
use App\Filament\Resources\Assets\RelationManagers\MovementsRelationManager;
use App\Filament\Resources\Movements\MovementResource;
use App\Filament\Resources\Movements\Pages\ListMovements;
use App\Models\Asset;
use App\Models\Department;
use App\Models\Holder;
use App\Models\Movement;
use App\Models\Room;
use App\Models\Service;
use App\Models\Site;
use App\Models\User;
use App\Policies\MovementPolicy;
use App\Support\DefaultRoles;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Route;

use function Pest\Livewire\livewire;

beforeEach(function () {
    Filament::setCurrentPanel(Filament::getPanel('admin'));

    $this->user = User::factory()->role(DefaultRoles::MANAGER)->create();
    $this->actingAs($this->user);

    $this->siege = Site::factory()->create(['name' => 'Siège']);
    $this->moungali = Site::factory()->create(['name' => 'Paroisse Moungali']);
    $this->louange = Department::factory()->create(['name' => 'Louange']);
    $this->jeunesse = Department::factory()->create(['name' => 'Jeunesse']);
    $this->salle = Room::factory()->create(['site_id' => $this->siege->id, 'name' => 'Salle polyvalente']);
    $this->david = Holder::factory()->create(['name' => 'David Massamba']);

    $this->asset = Asset::factory()->create([
        'number' => 'PAT-000087', 'site_id' => $this->siege->id, 'department_id' => $this->louange->id,
    ]);
    $this->asset = $this->asset->fresh();
    app(RecordInitialAssignment::class)($this->asset);
});

it('transfère un bien depuis la fiche (E14)', function () {
    livewire(ViewAsset::class, ['record' => $this->asset->getRouteKey()])
        ->callAction('transfer', [
            'site_id' => $this->siege->id,
            'department_id' => $this->jeunesse->id,
            'room_id' => $this->salle->id,
            'holder_id' => $this->david->id,
            'moved_on' => today()->toDateString(),
            'reason' => 'Réaffectation entre départements',
            'lock_version' => $this->asset->lock_version,
        ])
        ->assertHasNoActionErrors();

    $asset = $this->asset->fresh();

    expect($asset->department_id)->toBe($this->jeunesse->id)
        ->and($asset->holder_id)->toBe($this->david->id)
        ->and(Movement::query()->where('asset_id', $asset->id)->count())->toBe(2);
});

it('propose la réimpression de l\'étiquette quand le département change (RG-24)', function () {
    // Page simulée tant que le lot 4 n'est pas fusionné ; la vraie page sert ensuite.
    if (! TransferAction::labelsPageExists()) {
        Route::get('admin/etiquettes', fn () => 'ok');
    }

    livewire(ViewAsset::class, ['record' => $this->asset->getRouteKey()])
        ->callAction('transfer', [
            'site_id' => $this->siege->id,
            'department_id' => $this->jeunesse->id,
            'moved_on' => today()->toDateString(),
            'lock_version' => $this->asset->lock_version,
            'reprint_label' => true,
        ])
        ->assertRedirect(url('/admin/etiquettes').'?biens='.$this->asset->id);
});

it('ne propose pas la réimpression quand seul le responsable change', function () {
    livewire(ViewAsset::class, ['record' => $this->asset->getRouteKey()])
        ->callAction('transfer', [
            'site_id' => $this->siege->id,
            'department_id' => $this->louange->id,
            'holder_id' => $this->david->id,
            'moved_on' => today()->toDateString(),
            'lock_version' => $this->asset->lock_version,
            'reprint_label' => true,
        ])
        ->assertNoRedirect();

    expect($this->asset->fresh()->holder_id)->toBe($this->david->id);
});

it('refuse l\'enregistrement quand la fiche a changé depuis l\'ouverture du formulaire (RG-86)', function () {
    $opened = $this->asset->lock_version;
    $this->asset->update(['notes' => 'Modifié ailleurs']);

    livewire(ViewAsset::class, ['record' => $this->asset->getRouteKey()])
        ->callAction('transfer', [
            'site_id' => $this->siege->id,
            'department_id' => $this->jeunesse->id,
            'moved_on' => today()->toDateString(),
            'lock_version' => $opened,
        ]);

    expect(Movement::query()->where('asset_id', $this->asset->id)->count())->toBe(1)
        ->and($this->asset->fresh()->department_id)->toBe($this->louange->id);
});

it('garde la fenêtre ouverte et ne change rien quand le transfert est refusé', function () {
    livewire(ViewAsset::class, ['record' => $this->asset->getRouteKey()])
        ->callAction('transfer', [
            'site_id' => $this->siege->id,
            'department_id' => $this->louange->id,
            'moved_on' => today()->toDateString(),
            'lock_version' => $this->asset->lock_version,
        ])
        ->assertNotified('Transfert impossible');

    expect(Movement::query()->where('asset_id', $this->asset->id)->count())->toBe(1);
});

it('transfère plusieurs biens depuis la liste (E14, RG-23)', function () {
    $second = Asset::factory()->create(['site_id' => $this->moungali->id, 'department_id' => $this->louange->id]);

    livewire(ListAssets::class)
        ->callTableBulkAction('transfer', [$this->asset, $second], [
            'site_id' => $this->siege->id,
            'department_id' => $this->jeunesse->id,
            'moved_on' => today()->toDateString(),
            'reason' => 'Déménagement',
        ])
        ->assertHasNoTableBulkActionErrors();

    $moved = Movement::query()->where('type', '!=', MovementType::Initial)->get();

    expect($moved)->toHaveCount(2)
        ->and($moved->pluck('batch_uuid')->unique())->toHaveCount(1)
        ->and($second->fresh()->site_id)->toBe($this->siege->id);
});

it('masque l\'action Transférer à qui n\'a pas le droit', function (string $role) {
    $this->actingAs(User::factory()->role($role)->create());

    livewire(ViewAsset::class, ['record' => $this->asset->getRouteKey()])
        ->assertActionHidden('transfer');
})->with([DefaultRoles::VIEWER, DefaultRoles::ACCOUNTANT, DefaultRoles::DEPARTMENT_HEAD]);

it('masque l\'action Transférer sur un bien sorti du patrimoine', function () {
    $disposed = Asset::factory()->disposed()->create(['site_id' => $this->siege->id, 'department_id' => $this->louange->id]);

    livewire(ViewAsset::class, ['record' => $disposed->getRouteKey()])->assertActionHidden('transfer');
});

it('liste les mouvements avec leurs filtres (E13)', function () {
    app(TransferAssets::class)([$this->asset], ['site_id' => $this->siege->id, 'department_id' => $this->jeunesse->id, 'moved_on' => '2026-10-20']);
    $other = Asset::factory()->create(['site_id' => $this->moungali->id, 'department_id' => $this->louange->id]);
    app(RecordInitialAssignment::class)($other);

    $initialOfOther = Movement::query()->where('asset_id', $other->id)->sole();
    $transfer = Movement::query()->where('asset_id', $this->asset->id)->where('type', '!=', MovementType::Initial)->sole();

    livewire(ListMovements::class)
        ->assertCanSeeTableRecords(Movement::all())
        ->filterTable('type', MovementType::Initial->value)
        ->assertCanNotSeeTableRecords([$transfer])
        ->assertCanSeeTableRecords([$initialOfOther])
        ->removeTableFilters()
        ->filterTable('asset_id', $this->asset->id)
        ->assertCanNotSeeTableRecords([$initialOfOther])
        ->assertCanSeeTableRecords([$transfer])
        ->removeTableFilters()
        ->filterTable('department', $this->jeunesse->id)
        ->assertCanSeeTableRecords([$transfer])
        ->assertCanNotSeeTableRecords([$initialOfOther])
        ->removeTableFilters()
        ->filterTable('site', $this->moungali->id)
        ->assertCanSeeTableRecords([$initialOfOther])
        ->assertCanNotSeeTableRecords([$transfer])
        ->removeTableFilters()
        ->filterTable('created_by', $this->user->id)
        ->assertCanSeeTableRecords([$transfer])
        ->removeTableFilters()
        ->filterTable('period', ['from' => '2026-10-19', 'until' => '2026-10-21'])
        ->assertCanSeeTableRecords([$transfer]);
});

it('ne montre que les mouvements des biens du périmètre (RG-81)', function () {
    $mine = Asset::factory()->create(['department_id' => $this->louange->id]);
    $foreign = Asset::factory()->create(['department_id' => $this->jeunesse->id]);
    app(RecordInitialAssignment::class)($mine);
    app(RecordInitialAssignment::class)($foreign);

    $head = User::factory()->role(DefaultRoles::DEPARTMENT_HEAD)->create();
    $head->departments()->attach($this->louange);
    $this->actingAs($head);

    livewire(ListMovements::class)
        ->assertCanSeeTableRecords(Movement::query()->where('asset_id', $mine->id)->get())
        ->assertCanNotSeeTableRecords(Movement::query()->withoutGlobalScopes()->where('asset_id', $foreign->id)->get());
});

it('refuse l\'accès à la liste sans le droit de consulter', function () {
    $this->actingAs(User::factory()->role(DefaultRoles::DEPARTMENT_HEAD)->create());
    $this->get(MovementResource::getUrl('index'))->assertOk();

    $nobody = User::factory()->create();
    $this->actingAs($nobody);
    $this->get(MovementResource::getUrl('index'))->assertForbidden();
});

it('est en lecture seule : ni création, ni modification, ni suppression (RG-21)', function () {
    $movement = Movement::query()->where('asset_id', $this->asset->id)->sole();
    $policy = new MovementPolicy;

    expect(MovementResource::canCreate())->toBeFalse()
        ->and(array_keys(MovementResource::getPages()))->toBe(['index'])
        ->and($policy->update($this->user, $movement))->toBeFalse()
        ->and($policy->delete($this->user, $movement))->toBeFalse()
        ->and($policy->deleteAny($this->user))->toBeFalse()
        ->and(fn () => $movement->update(['reason' => 'Réécrit']))->toThrow(LogicException::class)
        ->and(fn () => $movement->delete())->toThrow(LogicException::class);
});

it('affiche l\'historique sur la fiche du bien, en lecture seule (E06)', function () {
    app(TransferAssets::class)([$this->asset], ['site_id' => $this->siege->id, 'department_id' => $this->jeunesse->id, 'moved_on' => '2026-10-20']);

    $movements = Movement::query()->where('asset_id', $this->asset->id)->get();

    livewire(MovementsRelationManager::class, ['ownerRecord' => $this->asset, 'pageClass' => ViewAsset::class])
        ->assertCanSeeTableRecords($movements)
        ->assertTableActionDoesNotExist('delete')
        ->assertCountTableRecords(2);
});

it('apparaît en première entrée du groupe Opérations', function () {
    expect(MovementResource::getNavigationGroup())->toBe('Opérations')
        ->and(MovementResource::getNavigationSort())->toBe(1)
        ->and(MovementResource::getNavigationLabel())->toBe('Mouvements');
});

it('utilise un service du bon département dans le formulaire', function () {
    $service = Service::factory()->create(['department_id' => $this->jeunesse->id, 'name' => 'Culte des jeunes']);

    livewire(ViewAsset::class, ['record' => $this->asset->getRouteKey()])
        ->callAction('transfer', [
            'site_id' => $this->siege->id,
            'department_id' => $this->jeunesse->id,
            'service_id' => $service->id,
            'moved_on' => today()->toDateString(),
            'lock_version' => $this->asset->lock_version,
        ])
        ->assertHasNoActionErrors();

    expect($this->asset->fresh()->service_id)->toBe($service->id);
});
