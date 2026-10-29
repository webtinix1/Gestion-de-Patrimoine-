<?php

use App\Actions\Users\SetUserActive;
use App\Actions\Users\SyncUserAccess;
use App\Filament\Pages\Auth\EditProfile;
use App\Filament\Pages\Auth\Login;
use App\Filament\Resources\Activities\ActivityResource;
use App\Http\Middleware\AddSecurityHeaders;
use App\Models\Acquisition;
use App\Models\Activity;
use App\Models\Asset;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use App\Services\System\ActivityLogProtection;
use App\Services\System\EnvironmentCheck;
use App\Support\DefaultRoles;
use Filament\Facades\Filament;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

use function Pest\Livewire\livewire;

/*
 * Correctifs de l'audit de sécurité du 29/10/2026 (lot 13).
 */

beforeEach(function () {
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    $this->admin = User::factory()->admin()->create();

    // Rôle personnalisé auquel on délègue la gestion des comptes.
    $this->delegate = User::factory()->role(DefaultRoles::MANAGER)->create();
    $this->delegate->givePermissionTo('users.view', 'users.create', 'users.update');
});

it('réserve la gestion d\'un compte administrateur aux administrateurs (S2)', function () {
    $user = User::factory()->role(DefaultRoles::VIEWER)->create();

    expect($this->delegate->can('update', $this->admin))->toBeFalse()
        ->and($this->delegate->can('resetPassword', $this->admin))->toBeFalse()
        ->and($this->delegate->can('deactivate', $this->admin))->toBeFalse()
        ->and($this->delegate->can('update', $user))->toBeTrue()
        ->and($this->admin->can('resetPassword', $user))->toBeTrue();
});

it('réserve la matrice des rôles aux administrateurs (S2)', function () {
    $role = Role::query()->where('name', DefaultRoles::VIEWER)->sole();

    expect($this->delegate->can('viewAny', Role::class))->toBeTrue()
        ->and($this->delegate->can('update', $role))->toBeFalse()
        ->and($this->delegate->can('create', Role::class))->toBeFalse()
        ->and($this->admin->can('update', $role))->toBeTrue();
});

it('refuse l\'attribution du rôle Administrateur par un non-administrateur (S2)', function () {
    $this->actingAs($this->delegate);
    $user = User::factory()->role(DefaultRoles::VIEWER)->create();

    expect(fn () => app(SyncUserAccess::class)($user, DefaultRoles::ADMIN, [], []))->toThrow(ValidationException::class)
        ->and(fn () => app(SyncUserAccess::class)($this->delegate, DefaultRoles::ADMIN, [], []))->toThrow(ValidationException::class)
        ->and($user->fresh()->hasRole(DefaultRoles::VIEWER))->toBeTrue()
        ->and($this->delegate->fresh()->hasRole(DefaultRoles::ADMIN))->toBeFalse();
});

it('interdit de changer son propre rôle ou son propre périmètre (S2)', function () {
    $this->actingAs($this->admin);
    User::factory()->admin()->create();

    expect(fn () => app(SyncUserAccess::class)($this->admin, DefaultRoles::VIEWER, [], []))->toThrow(ValidationException::class)
        ->and(fn () => app(SyncUserAccess::class)($this->admin, DefaultRoles::ADMIN, [Site::factory()->create()->id], []))->toThrow(ValidationException::class);

    // Enregistrer sa fiche sans rien changer reste possible.
    app(SyncUserAccess::class)($this->admin, DefaultRoles::ADMIN, [], []);

    expect($this->admin->fresh()->hasRole(DefaultRoles::ADMIN))->toBeTrue();
});

it('n\'accorde pas un périmètre plus large que le sien (S2)', function () {
    $own = Site::factory()->create();
    $other = Site::factory()->create();
    $this->delegate->sites()->attach($own);
    $this->actingAs($this->delegate);
    $user = User::factory()->role(DefaultRoles::VIEWER)->create();

    expect(fn () => app(SyncUserAccess::class)($user, DefaultRoles::VIEWER, [], []))->toThrow(ValidationException::class)
        ->and(fn () => app(SyncUserAccess::class)($user, DefaultRoles::VIEWER, [$own->id, $other->id], []))->toThrow(ValidationException::class);

    app(SyncUserAccess::class)($user, DefaultRoles::VIEWER, [$own->id], []);

    expect($user->fresh()->perimeterSiteIds())->toBe([$own->id]);
});

it('garde toujours un administrateur actif (S4)', function () {
    $this->actingAs($this->admin);
    $other = User::factory()->admin()->create();

    // Deux administrateurs : l'un peut rétrograder l'autre.
    app(SyncUserAccess::class)($other, DefaultRoles::MANAGER, [], []);

    // Le dernier ne peut ni être rétrogradé ni désactivé, même hors de l'écran.
    auth()->logout();

    expect(fn () => app(SyncUserAccess::class)($this->admin, DefaultRoles::MANAGER, [], []))->toThrow(ValidationException::class)
        ->and(fn () => app(SetUserActive::class)($this->admin, false))->toThrow(ValidationException::class)
        ->and($this->admin->fresh()->isAdministrator())->toBeTrue();
});

it('ferme les sessions d\'un compte désactivé (S10)', function () {
    config(['session.driver' => 'database']);
    $user = User::factory()->role(DefaultRoles::VIEWER)->create();
    DB::table('sessions')->insert([
        'id' => 'session-test', 'user_id' => $user->id, 'ip_address' => '127.0.0.1',
        'user_agent' => 'test', 'payload' => '', 'last_activity' => now()->timestamp,
    ]);

    app(SetUserActive::class)($user, false);

    expect(DB::table('sessions')->where('user_id', $user->id)->exists())->toBeFalse()
        ->and($user->fresh()->is_active)->toBeFalse();
});

it('répond pareil pour un compte bloqué et un identifiant inconnu, et trace chaque échec (S5, S17)', function () {
    $user = User::factory()->role(DefaultRoles::VIEWER)->create(['username' => 'b.mavoungou']);
    $user->forceFill(['locked_until' => now()->addMinutes(10)])->saveQuietly();

    $message = fn (string $username): string => livewire(Login::class)
        ->fillForm(['username' => $username, 'password' => 'mauvais1'])
        ->call('authenticate')
        ->errors()
        ->first('data.username');

    expect($message('b.mavoungou'))->toBe($message('inconnu'))
        ->and($message('inconnu'))->toBe(__('auth.failed'));

    RateLimiter::clear('livewire-rate-limiter:'.sha1(Login::class.'|authenticate|127.0.0.1'));
    $user->forceFill(['locked_until' => null])->saveQuietly();

    livewire(Login::class)
        ->fillForm(['username' => 'b.mavoungou', 'password' => 'mauvais1'])
        ->call('authenticate');

    $failures = Activity::query()->where('log_name', 'connexion')->where('event', 'failed')->get();

    expect($failures)->toHaveCount(3)
        ->and($failures->pluck('properties')->every(fn ($properties) => $properties['ip'] === '127.0.0.1'))->toBeTrue()
        ->and($user->fresh()->failed_attempts)->toBe(1);
});

it('trace la déconnexion (S17)', function () {
    $this->actingAs($this->admin);

    auth()->logout();

    expect(Activity::query()->where('log_name', 'connexion')->where('event', 'logout')->sole()->causer_id)->toBe($this->admin->id);
});

it('exige un nouveau mot de passe différent du provisoire et sans l\'identifiant (S16)', function () {
    $user = User::factory()->role(DefaultRoles::VIEWER)->create(['username' => 'c.ngoma', 'must_change_password' => true]);
    $this->actingAs($user);

    livewire(EditProfile::class)
        ->fillForm(['currentPassword' => 'password1', 'password' => 'password1', 'passwordConfirmation' => 'password1'])
        ->call('save')
        ->assertHasFormErrors(['password']);

    livewire(EditProfile::class)
        ->fillForm(['currentPassword' => 'password1', 'password' => 'c.ngoma2026', 'passwordConfirmation' => 'c.ngoma2026'])
        ->call('save')
        ->assertHasFormErrors(['password']);

    livewire(EditProfile::class)
        ->fillForm(['currentPassword' => 'password1', 'password' => '12345678', 'passwordConfirmation' => '12345678'])
        ->call('save')
        ->assertHasFormErrors(['password']);

    expect($user->fresh()->must_change_password)->toBeTrue();
});

it('filtre les acquisitions hors périmètre dans le journal (S3)', function () {
    $own = Site::factory()->create();
    $mine = Acquisition::factory()->create(['site_id' => $own->id]);
    $other = Acquisition::factory()->create();
    activity('amortissements')->performedOn($mine)->log('Validation A');
    activity('amortissements')->performedOn($other)->log('Validation B');

    $user = User::factory()->role(DefaultRoles::VIEWER)->create();
    $user->givePermissionTo('activity_log.view');
    $user->sites()->attach($own);
    $this->actingAs($user);

    expect(ActivityResource::getEloquentQuery()->pluck('description')->all())
        ->toContain('Validation A')
        ->not->toContain('Validation B');
});

it('refuse toute modification ou suppression du journal dans la base (S18)', function () {
    activity('biens')->log('Opération');

    expect(fn () => DB::table('activity_log')->update(['description' => 'falsifiée']))->toThrow(QueryException::class)
        ->and(fn () => DB::table('activity_log')->delete())->toThrow(QueryException::class)
        ->and(DB::table('activity_log')->where('description', 'Opération')->exists())->toBeTrue();
});

it('n\'accepte pour la photo d\'un bien qu\'un fichier du dossier des photos (S13)', function () {
    $asset = Asset::factory()->create();

    expect(fn () => $asset->update(['photo_path' => 'documents/secret.pdf']))->toThrow(ValidationException::class)
        ->and(fn () => $asset->update(['photo_path' => Asset::PHOTO_DIRECTORY.'/../documents/secret.pdf']))->toThrow(ValidationException::class);

    $asset->refresh()->update(['photo_path' => Asset::PHOTO_DIRECTORY.'/a.jpg']);

    expect($asset->fresh()->photo_path)->toBe(Asset::PHOTO_DIRECTORY.'/a.jpg');
});

it('pose les en-têtes de sécurité sur les pages (S6)', function () {
    $response = $this->get('/admin/login');

    foreach (AddSecurityHeaders::HEADERS as $name => $value) {
        $response->assertHeader($name, $value);
    }
});

it('signale un journal non verrouillé en base et le répare (S18, lot 14)', function () {
    // Doublure : poser ou retirer de vrais déclencheurs validerait la transaction du test sous MySQL.
    $protection = new class extends ActivityLogProtection
    {
        public bool $installed = false;

        public bool $allowed = false;

        public function isInstalled(): bool
        {
            return $this->installed;
        }

        public function install(): bool
        {
            return $this->installed = $this->allowed;
        }
    };
    app()->instance(ActivityLogProtection::class, $protection);
    $check = app(EnvironmentCheck::class);

    // Refus de MySQL faute de privilège : avertissement avec la marche à suivre.
    expect($check->activityLogProtection(repair: true))
        ->status->toBe('warn')
        ->detail->toContain('log_bin_trust_function_creators');

    $protection->allowed = true;

    expect($check->activityLogProtection()['status'])->toBe('warn')
        ->and($check->activityLogProtection(repair: true)['status'])->toBe('ok')
        ->and($protection->installed)->toBeTrue();
});

it('pose réellement les déclencheurs du journal sur la base de test (S18, lot 14)', function () {
    expect(app(ActivityLogProtection::class)->isInstalled())->toBeTrue();
});
