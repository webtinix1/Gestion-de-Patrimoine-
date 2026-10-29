<?php

use App\Filament\Pages\Auth\Login;
use App\Models\User;
use Filament\Facades\Filament;

use function Pest\Livewire\livewire;

beforeEach(function () {
    Filament::setCurrentPanel(Filament::getPanel('admin'));
});

it('affiche un champ identifiant et aucun champ e-mail', function () {
    $this->get('/admin/login')
        ->assertOk()
        ->assertSee('Identifiant')
        ->assertDontSee('Adresse e-mail');

    livewire(Login::class)
        ->assertFormFieldExists('username')
        ->assertFormFieldDoesNotExist('email');
});

it('connecte un utilisateur avec son identifiant', function () {
    $user = User::factory()->create(['username' => 'jmoukala']);

    livewire(Login::class)
        ->fillForm(['username' => 'jmoukala', 'password' => 'password1'])
        ->call('authenticate')
        ->assertHasNoFormErrors()
        ->assertRedirect('/admin');

    $this->assertAuthenticatedAs($user);
});

it('refuse un mot de passe incorrect avec un message en français', function () {
    User::factory()->create(['username' => 'jmoukala']);

    livewire(Login::class)
        ->fillForm(['username' => 'jmoukala', 'password' => 'mauvais1'])
        ->call('authenticate')
        ->assertHasFormErrors(['username' => __('auth.failed')]);

    $this->assertGuest();
});

it('refuse la connexion par adresse e-mail', function () {
    User::factory()->create(['username' => 'jmoukala', 'email' => 'j.moukala@example.org']);

    livewire(Login::class)
        ->fillForm(['username' => 'j.moukala@example.org', 'password' => 'password1'])
        ->call('authenticate')
        ->assertHasFormErrors(['username']);

    $this->assertGuest();
});

it('refuse un compte désactivé (RG-84)', function () {
    User::factory()->inactive()->create(['username' => 'ancien']);

    livewire(Login::class)
        ->fillForm(['username' => 'ancien', 'password' => 'password1'])
        ->call('authenticate')
        ->assertHasFormErrors(['username']);

    $this->assertGuest();
});

it('exige identifiant et mot de passe', function () {
    livewire(Login::class)
        ->fillForm(['username' => '', 'password' => ''])
        ->call('authenticate')
        ->assertHasFormErrors(['username' => 'required', 'password' => 'required']);
});

it('redirige la page d\'accueil vers le panneau, puis vers la connexion', function () {
    $this->get('/')->assertRedirect('/admin');
    $this->get('/admin')->assertRedirect('/admin/login');
});

it('ouvre le tableau de bord pour un utilisateur connecté', function () {
    $this->actingAs(User::factory()->create())
        ->get('/admin')
        ->assertOk()
        ->assertSee('Tableau de bord');
});
