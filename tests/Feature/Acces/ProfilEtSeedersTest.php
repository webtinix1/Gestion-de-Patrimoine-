<?php

use App\Filament\Pages\Auth\EditProfile;
use App\Models\Site;
use App\Models\User;
use App\Support\DefaultRoles;
use Database\Seeders\DemoSeeder;
use Database\Seeders\UsersDemoSeeder;
use Filament\Facades\Filament;

use function Pest\Livewire\livewire;

beforeEach(function () {
    Filament::setCurrentPanel(Filament::getPanel('admin'));
});

it('affiche le profil avec le rôle et le périmètre en lecture (E04)', function () {
    $user = User::factory()->role(DefaultRoles::MANAGER)->create();
    $user->sites()->attach(Site::factory()->create(['name' => 'Paroisse Moungali']));

    $this->actingAs($user)->get(Filament::getProfileUrl())
        ->assertOk()
        ->assertSee('Mon profil')
        ->assertSee('Gestionnaire du patrimoine')
        ->assertSee('Paroisse Moungali')
        ->assertSee('Tous les départements');
});

it('met à jour ses informations sans changer de mot de passe', function () {
    $user = User::factory()->role(DefaultRoles::MANAGER)->create();
    $this->actingAs($user);

    livewire(EditProfile::class)
        ->fillForm(['phone' => '+242 06 000 00 00', 'job_title' => 'Responsable logistique'])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($user->refresh())
        ->phone->toBe('+242 06 000 00 00')
        ->job_title->toBe('Responsable logistique');
});

it('exige le mot de passe actuel pour en changer', function () {
    $this->actingAs(User::factory()->role(DefaultRoles::MANAGER)->create());

    livewire(EditProfile::class)
        ->fillForm(['currentPassword' => 'mauvais1', 'password' => 'Nouveau2026', 'passwordConfirmation' => 'Nouveau2026'])
        ->call('save')
        ->assertHasFormErrors(['currentPassword']);
});

it('charge des comptes de démonstration pour les cinq rôles', function () {
    $this->seed();

    $roles = User::query()->with('roles')->get()->flatMap->getRoleNames()->unique()->sort()->values()->all();

    expect($roles)->toBe(collect(DefaultRoles::names())->sort()->values()->all())
        ->and(User::query()->count())->toBe(count(UsersDemoSeeder::USERS) + 1)
        ->and(User::query()->where('username', DemoSeeder::ADMIN_USERNAME)->sole()->hasRole(DefaultRoles::ADMIN))->toBeTrue()
        ->and(User::query()->where('username', 'e.mbemba')->sole()->perimeterDepartmentIds())->toHaveCount(1)
        ->and(User::query()->where('username', 'f.bakala')->sole()->is_active)->toBeFalse()
        ->and(Site::query()->where('code', 'SIE')->sole()->manager?->username)->toBe('a.okemba');
});
