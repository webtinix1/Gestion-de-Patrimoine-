<?php

use App\Filament\Pages\Auth\Login;
use App\Filament\Resources\Activities\Pages\ListActivities;
use App\Models\Activity;
use App\Models\Asset;
use App\Models\Department;
use App\Models\User;
use App\Support\DefaultRoles;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;

use function Pest\Livewire\livewire;

beforeEach(function () {
    Filament::setCurrentPanel(Filament::getPanel('admin'));
});

it('trace la connexion avec l\'utilisateur, la date et le poste (RG-71)', function () {
    $user = User::factory()->admin()->create(['username' => 'a.okemba']);

    livewire(Login::class)
        ->fillForm(['username' => 'a.okemba', 'password' => 'password1'])
        ->call('authenticate');

    $activity = Activity::query()->where('log_name', 'connexion')->sole();

    expect($activity->description)->toBe('Connexion')
        ->and($activity->causer_id)->toBe($user->id)
        ->and($activity->ip())->toBe('127.0.0.1')
        ->and($activity->created_at)->not->toBeNull();
});

it('trace la modification d\'un utilisateur avec avant et après, sans le mot de passe', function () {
    $this->actingAs(User::factory()->admin()->create());
    $user = User::factory()->create(['job_title' => 'Secrétaire']);

    $user->update(['job_title' => 'Trésorier', 'password' => 'Secret2026']);

    $activity = Activity::query()->where('subject_id', $user->id)->where('event', 'updated')->sole();

    expect($activity->properties->get('old'))->toBe(['job_title' => 'Secrétaire'])
        ->and($activity->properties->get('attributes'))->toBe(['job_title' => 'Trésorier']);
});

it('interdit de modifier ou de supprimer une entrée du journal (RG-72)', function () {
    $activity = activity('test')->log('Opération');

    expect(fn () => $activity->update(['description' => 'Falsifié']))->toThrow(LogicException::class)
        ->and(fn () => $activity->delete())->toThrow(LogicException::class)
        ->and(Activity::query()->sole()->description)->toBe('Opération');
});

it('affiche le journal en lecture seule', function () {
    $admin = User::factory()->admin()->create();
    $this->actingAs($admin);
    $entry = activity('utilisateurs')->causedBy($admin)->performedOn($admin)
        ->withProperties(['old' => ['job_title' => 'A'], 'attributes' => ['job_title' => 'B']])
        ->log('Modification d\'un utilisateur');

    livewire(ListActivities::class)
        ->assertCanSeeTableRecords([$entry])
        ->assertSee('Modification d\'un utilisateur')
        ->assertActionDoesNotExist(TestAction::make(EditAction::class)->table($entry))
        ->assertActionDoesNotExist(TestAction::make(DeleteAction::class)->table($entry));

    $this->get('/admin/journal/create')->assertNotFound();
});

it('n\'affiche pas les opérations sur des biens hors du périmètre (RG-81)', function () {
    $louange = Department::factory()->create();
    $jeunesse = Department::factory()->create();
    $own = Asset::factory()->create(['department_id' => $louange->id]);
    $other = Asset::factory()->create(['department_id' => $jeunesse->id]);
    $ownEntry = activity('biens')->performedOn($own)->log('Modification d\'une fiche');
    $otherEntry = activity('biens')->performedOn($other)->log('Modification d\'une fiche');
    $loginEntry = activity('connexion')->log('Connexion');

    $manager = User::factory()->role(DefaultRoles::MANAGER)->create();
    $manager->departments()->attach($louange);
    $this->actingAs($manager);

    livewire(ListActivities::class)
        ->assertCanSeeTableRecords([$ownEntry, $loginEntry])
        ->assertCanNotSeeTableRecords([$otherEntry]);
});

it('réserve le journal aux rôles qui ont le droit de le consulter', function () {
    $this->actingAs(User::factory()->role(DefaultRoles::DEPARTMENT_HEAD)->create())
        ->get('/admin/journal')
        ->assertForbidden();
});
