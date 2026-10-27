<?php

use App\Actions\Users\ResetUserPassword;
use App\Filament\Pages\Auth\EditProfile;
use App\Filament\Pages\Auth\Login;
use App\Models\Activity;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;

use function Pest\Livewire\livewire;

beforeEach(function () {
    Filament::setCurrentPanel(Filament::getPanel('admin'));
});

function failLogin(string $username): void
{
    RateLimiter::clear('livewire-rate-limiter:'.sha1(Login::class.'|authenticate|127.0.0.1'));

    livewire(Login::class)
        ->fillForm(['username' => $username, 'password' => 'mauvais1'])
        ->call('authenticate');
}

it('bloque le compte 15 minutes après 5 échecs (RG-85)', function () {
    $user = User::factory()->admin()->create(['username' => 'a.okemba']);

    foreach (range(1, 5) as $attempt) {
        failLogin('a.okemba');
    }

    expect($user->refresh()->isLocked())->toBeTrue()
        ->and($user->locked_until->diffInMinutes(now(), true))->toBeGreaterThan(14);

    livewire(Login::class)
        ->fillForm(['username' => 'a.okemba', 'password' => 'password1'])
        ->call('authenticate')
        ->assertHasFormErrors(['username']);

    $this->assertGuest();
    expect(Activity::query()->where('event', 'locked')->count())->toBe(1);

    $this->travel(16)->minutes();

    livewire(Login::class)
        ->fillForm(['username' => 'a.okemba', 'password' => 'password1'])
        ->call('authenticate')
        ->assertHasNoFormErrors();

    $this->assertAuthenticatedAs($user);
});

it('remet le compteur d\'échecs à zéro après une connexion réussie', function () {
    $user = User::factory()->admin()->create(['username' => 'a.okemba']);

    failLogin('a.okemba');
    failLogin('a.okemba');
    expect($user->refresh()->failed_attempts)->toBe(2);

    livewire(Login::class)
        ->fillForm(['username' => 'a.okemba', 'password' => 'password1'])
        ->call('authenticate');

    expect($user->refresh())
        ->failed_attempts->toBe(0)
        ->last_login_at->not->toBeNull()
        ->last_login_ip->toBe('127.0.0.1');
});

it('ne propose pas « rester connecté », qui contournerait la déconnexion après inactivité', function () {
    livewire(Login::class)->assertFormFieldDoesNotExist('remember');

    expect(config('session.lifetime'))->toBe(30);
});

it('affiche le message pour un mot de passe oublié', function () {
    $this->get('/admin/login')->assertSee('contactez l\'administrateur');
});

it('impose 8 caractères avec un chiffre (RG-85)', function (string $password) {
    $user = User::factory()->admin()->create();
    $this->actingAs($user);

    livewire(EditProfile::class)
        ->fillForm(['currentPassword' => 'password1', 'password' => $password, 'passwordConfirmation' => $password])
        ->call('save')
        ->assertHasFormErrors(['password']);
})->with(['court1', 'sanschiffre']);

it('oblige à changer un mot de passe provisoire avant d\'utiliser l\'application', function () {
    $user = User::factory()->admin()->create();
    $temporary = app(ResetUserPassword::class)($user);

    expect($user->refresh()->must_change_password)->toBeTrue()
        ->and(Hash::check($temporary, $user->password))->toBeTrue()
        ->and($temporary)->toMatch('/\d/')
        ->and(strlen($temporary))->toBeGreaterThanOrEqual(8);

    $this->actingAs($user);
    $this->get('/admin/sites')->assertRedirect(Filament::getProfileUrl());
    $this->get(Filament::getProfileUrl())->assertOk()->assertSee('Mot de passe provisoire');

    livewire(EditProfile::class)
        ->fillForm(['currentPassword' => $temporary, 'password' => 'Nouveau2026', 'passwordConfirmation' => 'Nouveau2026'])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($user->refresh()->must_change_password)->toBeFalse()
        ->and(Hash::check('Nouveau2026', $user->password))->toBeTrue();

    // Nouvelle session après le changement de mot de passe.
    $this->flushSession();
    $this->actingAs($user->refresh())->get('/admin/sites')->assertOk();
});

it('débloque le compte lors d\'une réinitialisation par l\'administrateur', function () {
    $user = User::factory()->create(['locked_until' => now()->addMinutes(10), 'failed_attempts' => 3]);

    app(ResetUserPassword::class)($user);

    expect($user->refresh()->isLocked())->toBeFalse()
        ->and($user->failed_attempts)->toBe(0);
});
