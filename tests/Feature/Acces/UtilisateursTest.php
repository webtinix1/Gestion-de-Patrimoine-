<?php

use App\Filament\Pages\Auth\Login;
use App\Filament\Resources\Users\Pages\CreateUser;
use App\Filament\Resources\Users\Pages\EditUser;
use App\Filament\Resources\Users\Pages\ListUsers;
use App\Models\Activity;
use App\Models\Department;
use App\Models\Site;
use App\Models\User;
use App\Support\DefaultRoles;
use Filament\Actions\DeleteAction;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;

use function Pest\Livewire\livewire;

beforeEach(function () {
    $this->admin = User::factory()->admin()->create();
    $this->actingAs($this->admin);
    Filament::setCurrentPanel(Filament::getPanel('admin'));
});

it('crée un compte avec rôle, périmètre et mot de passe provisoire', function () {
    $site = Site::factory()->create();
    $department = Department::factory()->create();

    livewire(CreateUser::class)
        ->fillForm([
            'first_name' => 'Emmanuel',
            'last_name' => 'Mbemba',
            'username' => 'E.Mbemba',
            'role' => DefaultRoles::DEPARTMENT_HEAD,
            'sites' => [$site->id],
            'departments' => [$department->id],
        ])
        ->call('create')
        ->assertHasNoFormErrors()
        ->assertNotified();

    $user = User::query()->where('username', 'e.mbemba')->sole();

    expect($user->must_change_password)->toBeTrue()
        ->and($user->is_active)->toBeTrue()
        ->and($user->hasRole(DefaultRoles::DEPARTMENT_HEAD))->toBeTrue()
        ->and($user->perimeterSiteIds())->toBe([$site->id])
        ->and($user->perimeterDepartmentIds())->toBe([$department->id]);
});

it('exige un identifiant unique et un rôle', function () {
    User::factory()->create(['username' => 'a.okemba']);

    livewire(CreateUser::class)
        ->fillForm(['first_name' => 'A', 'last_name' => 'B', 'username' => 'a.okemba', 'role' => null])
        ->call('create')
        ->assertHasFormErrors(['username' => 'unique', 'role' => 'required']);
});

it('trace le changement de rôle et de périmètre avec avant et après (RG-70)', function () {
    $user = User::factory()->role(DefaultRoles::MANAGER)->create();
    $site = Site::factory()->create(['name' => 'Paroisse Moungali']);

    livewire(EditUser::class, ['record' => $user->getRouteKey()])
        ->fillForm(['role' => DefaultRoles::VIEWER, 'sites' => [$site->id]])
        ->call('save')
        ->assertHasNoFormErrors();

    $activity = Activity::query()->where('event', 'access_updated')->sole();

    expect($activity->properties->get('old'))->toMatchArray(['rôle' => DefaultRoles::MANAGER, 'sites' => 'Tous'])
        ->and($activity->properties->get('attributes'))->toMatchArray(['rôle' => DefaultRoles::VIEWER, 'sites' => 'Paroisse Moungali'])
        ->and($activity->causer_id)->toBe($this->admin->id);
});

it('désactive un compte sans le supprimer ni perdre son historique (RG-84)', function () {
    $user = User::factory()->role(DefaultRoles::MANAGER)->create(['username' => 'f.bakala']);
    activity('test')->causedBy($user)->log('Opération passée');

    livewire(ListUsers::class)
        ->assertActionDoesNotExist(TestAction::make(DeleteAction::class)->table($user))
        ->callAction(TestAction::make('deactivate')->table($user));

    expect($user->refresh()->is_active)->toBeFalse()
        ->and(Activity::query()->where('causer_id', $user->id)->where('log_name', 'test')->exists())->toBeTrue();

    auth()->logout();

    livewire(Login::class)
        ->fillForm(['username' => 'f.bakala', 'password' => 'password1'])
        ->call('authenticate')
        ->assertHasFormErrors(['username']);
});

it('empêche de désactiver son propre compte', function () {
    livewire(ListUsers::class)
        ->assertActionHidden(TestAction::make('deactivate')->table($this->admin));
});

it('réinitialise le mot de passe et affiche le provisoire une seule fois', function () {
    $user = User::factory()->create();

    livewire(ListUsers::class)
        ->callAction(TestAction::make('resetPassword')->table($user))
        ->assertNotified();

    expect($user->refresh()->must_change_password)->toBeTrue()
        ->and(Activity::query()->where('event', 'password_reset')->where('subject_id', $user->id)->exists())->toBeTrue();
});
