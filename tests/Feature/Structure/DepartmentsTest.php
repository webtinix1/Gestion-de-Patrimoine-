<?php

use App\Filament\Resources\Departments\Pages\CreateDepartment;
use App\Filament\Resources\Departments\Pages\EditDepartment;
use App\Filament\Resources\Departments\Pages\ListDepartments;
use App\Filament\Resources\Departments\RelationManagers\ServicesRelationManager;
use App\Models\Department;
use App\Models\Room;
use App\Models\Service;
use App\Models\Site;
use App\Models\User;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;

use function Pest\Livewire\livewire;

beforeEach(function () {
    $this->user = User::factory()->admin()->create();
    $this->actingAs($this->user);
    Filament::setCurrentPanel(Filament::getPanel('admin'));
});

it('crée un département rattaché à plusieurs sites', function () {
    $sites = Site::factory()->count(2)->create();

    livewire(CreateDepartment::class)
        ->fillForm([
            'name' => 'Louange',
            'code' => 'LOU',
            'sites' => $sites->pluck('id')->all(),
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $department = Department::query()->sole();

    expect($department->sites->pluck('id')->sort()->values()->all())
        ->toBe($sites->pluck('id')->sort()->values()->all());
});

it('ne propose que les sites actifs', function () {
    $inactive = Site::factory()->inactive()->create();

    livewire(CreateDepartment::class)
        ->fillForm(['name' => 'Louange', 'code' => 'LOU', 'sites' => [$inactive->id]])
        ->call('create')
        ->assertHasFormErrors(['sites']);
});

it('affiche les départements avec leurs services', function () {
    $department = Department::factory()->create(['name' => 'Louange']);
    Service::factory()->for($department)->create(['name' => 'Chorale']);
    Service::factory()->for($department)->create(['name' => 'Orchestre']);

    livewire(ListDepartments::class)
        ->assertCanSeeTableRecords([$department])
        ->assertSee(['Chorale', 'Orchestre']);
});

it('filtre les départements par site', function () {
    $siege = Site::factory()->create();
    $annexe = Site::factory()->create();
    $finances = Department::factory()->create();
    $finances->sites()->attach($siege);
    $jeunesse = Department::factory()->create();
    $jeunesse->sites()->attach($annexe);

    livewire(ListDepartments::class)
        ->filterTable('sites', $siege->id)
        ->assertCanSeeTableRecords([$finances])
        ->assertCanNotSeeTableRecords([$jeunesse]);
});

it('ajoute un service depuis la fiche du département', function () {
    $department = Department::factory()->create();

    livewire(ServicesRelationManager::class, [
        'ownerRecord' => $department,
        'pageClass' => EditDepartment::class,
    ])
        ->callAction(TestAction::make(CreateAction::class)->table(), ['name' => 'Chorale', 'code' => 'cho'])
        ->assertHasNoFormErrors();

    expect($department->services()->sole())
        ->name->toBe('Chorale')
        ->code->toBe('CHO');
});

it('refuse deux services de même code dans un département', function () {
    $department = Department::factory()->create();
    Service::factory()->for($department)->create(['code' => 'CHO']);

    livewire(ServicesRelationManager::class, [
        'ownerRecord' => $department,
        'pageClass' => EditDepartment::class,
    ])
        ->callAction(TestAction::make(CreateAction::class)->table(), ['name' => 'Chœur', 'code' => 'CHO'])
        ->assertHasFormErrors(['code' => 'unique']);
});

it('ne supprime pas un département qui a des services ou des locaux', function (string $usage) {
    $department = Department::factory()->create();
    match ($usage) {
        'service' => Service::factory()->for($department)->create(),
        'local' => Room::factory()->for($department)->create(),
    };

    livewire(ListDepartments::class)
        ->assertActionHidden(TestAction::make(DeleteAction::class)->table($department));
})->with(['service', 'local']);

it('supprime un département vide', function () {
    $department = Department::factory()->create();

    livewire(EditDepartment::class, ['record' => $department->getRouteKey()])
        ->callAction(DeleteAction::class);

    expect(Department::query()->count())->toBe(0);
});

it('désactive un service sans le supprimer', function () {
    $department = Department::factory()->create();
    $service = Service::factory()->for($department)->create();

    livewire(ServicesRelationManager::class, [
        'ownerRecord' => $department,
        'pageClass' => EditDepartment::class,
    ])
        ->callAction(TestAction::make('deactivate')->table($service));

    expect($service->refresh()->is_active)->toBeFalse();
});
