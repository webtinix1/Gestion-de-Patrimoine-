<?php

use App\Exceptions\ReferentialInUseException;
use App\Filament\Resources\Sites\Pages\CreateSite;
use App\Filament\Resources\Sites\Pages\EditSite;
use App\Filament\Resources\Sites\Pages\ListSites;
use App\Models\Department;
use App\Models\Room;
use App\Models\Site;
use App\Models\User;
use Filament\Actions\DeleteAction;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;

use function Pest\Livewire\livewire;

beforeEach(function () {
    $this->user = User::factory()->admin()->create();
    $this->actingAs($this->user);
    Filament::setCurrentPanel(Filament::getPanel('admin'));
});

it('crée un site, code en majuscules, auteur enregistré', function () {
    $manager = User::factory()->create();

    livewire(CreateSite::class)
        ->fillForm([
            'name' => 'Paroisse Poto-Poto',
            'code' => 'pot',
            'address' => 'Poto-Poto, Brazzaville',
            'manager_id' => $manager->id,
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $site = Site::query()->sole();

    expect($site->code)->toBe('POT')
        ->and($site->is_active)->toBeTrue()
        ->and($site->manager_id)->toBe($manager->id)
        ->and($site->getAttribute('created_by'))->toBe($this->user->id);
});

it('exige un nom et un code unique', function () {
    Site::factory()->create(['code' => 'SIE']);

    livewire(CreateSite::class)
        ->fillForm(['name' => '', 'code' => 'SIE'])
        ->call('create')
        ->assertHasFormErrors(['name' => 'required', 'code' => 'unique']);
});

it('refuse un code avec des caractères spéciaux', function () {
    livewire(CreateSite::class)
        ->fillForm(['name' => 'Siège', 'code' => 'SI È'])
        ->call('create')
        ->assertHasFormErrors(['code' => 'regex']);
});

it('modifie un site', function () {
    $site = Site::factory()->create(['name' => 'Siège']);

    livewire(EditSite::class, ['record' => $site->getRouteKey()])
        ->fillForm(['name' => 'Siège central'])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($site->refresh()->name)->toBe('Siège central')
        ->and($site->getAttribute('updated_by'))->toBe($this->user->id);
});

it('désactive puis réactive un site', function () {
    $site = Site::factory()->create();

    livewire(ListSites::class)
        ->assertActionHidden(TestAction::make('reactivate')->table($site))
        ->callAction(TestAction::make('deactivate')->table($site));

    expect($site->refresh()->is_active)->toBeFalse();

    livewire(ListSites::class)
        ->assertActionHidden(TestAction::make('deactivate')->table($site))
        ->callAction(TestAction::make('reactivate')->table($site));

    expect($site->refresh()->is_active)->toBeTrue();
});

it('supprime un site inutilisé', function () {
    $site = Site::factory()->create();

    livewire(ListSites::class)
        ->assertActionVisible(TestAction::make(DeleteAction::class)->table($site))
        ->callAction(TestAction::make(DeleteAction::class)->table($site));

    expect(Site::query()->count())->toBe(0);
});

it('ne propose pas la suppression d\'un site utilisé par un local', function () {
    $site = Site::factory()->create();
    Room::factory()->for($site)->create();

    livewire(ListSites::class)
        ->assertActionHidden(TestAction::make(DeleteAction::class)->table($site));

    livewire(EditSite::class, ['record' => $site->getRouteKey()])
        ->assertActionHidden(DeleteAction::class);
});

it('ne propose pas la suppression d\'un site rattaché à un département', function () {
    $site = Site::factory()->create();
    Department::factory()->create()->sites()->attach($site);

    expect($site->isInUse())->toBeTrue()
        ->and($this->user->can('delete', $site))->toBeFalse();
});

it('refuse la suppression d\'un site utilisé même hors de l\'interface', function () {
    $site = Site::factory()->create();
    Room::factory()->for($site)->create();

    expect(fn () => $site->delete())->toThrow(ReferentialInUseException::class);
    expect(Site::query()->whereKey($site->id)->exists())->toBeTrue();
});

it('filtre les sites actifs et inactifs', function () {
    $active = Site::factory()->create();
    $inactive = Site::factory()->inactive()->create();

    livewire(ListSites::class)
        ->filterTable('is_active', false)
        ->assertCanSeeTableRecords([$inactive])
        ->assertCanNotSeeTableRecords([$active]);
});
