<?php

use App\Actions\Alerts\RefreshAlerts;
use App\Actions\Settings\UpdateSettings;
use App\Filament\Pages\SettingsPage;
use App\Models\Activity;
use App\Models\Asset;
use App\Models\AssetCategory;
use App\Models\Department;
use App\Models\Setting;
use App\Models\User;
use App\Services\Alerts\AlertGenerator;
use App\Services\AssetListReport;
use App\Services\AssetNumberGenerator;
use App\Services\Settings;
use App\Services\System\EnvironmentCheck;
use App\Services\System\ServerStatus;
use App\Support\DefaultRoles;
use Filament\Facades\Filament;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

use function Pest\Livewire\livewire;

beforeEach(function () {
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    $this->admin = User::factory()->role(DefaultRoles::ADMIN)->create();
});

function saveSettings(array $data): void
{
    app(UpdateSettings::class)($data);
}

function settingErrors(array $data): array
{
    try {
        saveSettings($data);
    } catch (ValidationException $exception) {
        return $exception->errors();
    }

    return [];
}

it('réserve l\'écran Paramètres à l\'administrateur (policy, URL directe, Livewire)', function () {
    $this->actingAs($this->admin);
    $this->get('/admin/parametres')->assertOk()->assertSee('Numérotation')->assertSee('État du serveur');
    expect(Gate::allows('viewAny', Setting::class))->toBeTrue();

    foreach ([DefaultRoles::MANAGER, DefaultRoles::ACCOUNTANT, DefaultRoles::DEPARTMENT_HEAD, DefaultRoles::VIEWER] as $role) {
        $user = User::factory()->role($role)->create();
        $this->actingAs($user);

        expect(Gate::allows('viewAny', Setting::class))->toBeFalse()
            ->and(Gate::allows('update', Setting::class))->toBeFalse();
        $this->get('/admin/parametres')->assertForbidden();
        livewire(SettingsPage::class)->assertForbidden();
        expect(fn () => saveSettings([Settings::CHURCH_NAME => 'Pirate']))->toThrow(AuthorizationException::class);
    }

    expect(app(Settings::class)->string(Settings::CHURCH_NAME))->not->toBe('Pirate');
});

it('refuse à un administrateur désactivé ou à un rôle personnalisé muni du droit Paramètres', function () {
    $custom = User::factory()->role(DefaultRoles::MANAGER)->create();
    $custom->givePermissionTo('settings.view', 'settings.update');
    $this->actingAs($custom);

    expect(Gate::allows('viewAny', Setting::class))->toBeFalse();

    $this->actingAs(User::factory()->role(DefaultRoles::ADMIN)->inactive()->create());
    expect(Gate::allows('update', Setting::class))->toBeFalse();
});

it('enregistre l\'identité depuis la page et vide le cache de Settings', function () {
    $this->actingAs($this->admin);

    livewire(SettingsPage::class)
        ->assertFormSet([Settings::CHURCH_NAME => '[Nom de l\'Église]'])
        ->fillForm([Settings::CHURCH_NAME => '  Église   Évangélique Exemple ', Settings::CHURCH_ACRONYM => 'EEE'])
        ->callAction('save')
        ->assertHasNoFormErrors();

    expect(app(Settings::class)->string(Settings::CHURCH_NAME))->toBe('Église Évangélique Exemple')
        ->and(app(Settings::class)->string(Settings::CHURCH_ACRONYM))->toBe('EEE');
});

it('reprend l\'identité dans un PDF existant (liste des biens)', function () {
    $this->actingAs($this->admin);
    Asset::factory()->create(['designation' => 'Microphone', 'department_id' => Department::factory()->create()->id]);

    saveSettings([Settings::CHURCH_NAME => 'Église Évangélique Exemple', Settings::CHURCH_ACRONYM => 'EEE']);

    $data = app(AssetListReport::class)->build(Asset::query()->orderBy('number'), []);
    $html = view('pdf.assets-list', ['data' => $data])->render();

    expect($html)->toContain('Église Évangélique Exemple')->toContain('(EEE)');
});

it('valide l\'identité côté serveur', function () {
    $this->actingAs($this->admin);

    expect(settingErrors([Settings::CHURCH_NAME => '   ']))->toHaveKey(Settings::CHURCH_NAME)
        ->and(settingErrors([Settings::CHURCH_NAME => str_repeat('a', 121)]))->toHaveKey(Settings::CHURCH_NAME)
        ->and(settingErrors([Settings::CHURCH_ACRONYM => str_repeat('A', 13)]))->toHaveKey(Settings::CHURCH_ACRONYM)
        ->and(settingErrors([Settings::CHURCH_ACRONYM => ['x']]))->toHaveKey(Settings::CHURCH_ACRONYM);
});

it('valide les bornes des délais d\'alerte (RG-90) et les applique au calcul des alertes', function () {
    $this->actingAs($this->admin);

    expect(settingErrors([Settings::ALERT_LOAN_DUE_DAYS => -1]))->toHaveKey(Settings::ALERT_LOAN_DUE_DAYS)
        ->and(settingErrors([Settings::ALERT_LOAN_DUE_DAYS => 61]))->toHaveKey(Settings::ALERT_LOAN_DUE_DAYS)
        ->and(settingErrors([Settings::ALERT_MAINTENANCE_DAYS => 0]))->toHaveKey(Settings::ALERT_MAINTENANCE_DAYS)
        ->and(settingErrors([Settings::ALERT_MAINTENANCE_DAYS => 366]))->toHaveKey(Settings::ALERT_MAINTENANCE_DAYS)
        ->and(settingErrors([Settings::ALERT_INVENTORY_DAYS => 'abc']))->toHaveKey(Settings::ALERT_INVENTORY_DAYS)
        ->and(settingErrors([Settings::ALERT_DEPRECIATION_MONTHS => 25]))->toHaveKey(Settings::ALERT_DEPRECIATION_MONTHS)
        ->and(settingErrors([Settings::ALERT_DEPRECIATION_MONTHS => 1.5]))->toHaveKey(Settings::ALERT_DEPRECIATION_MONTHS);

    saveSettings([
        Settings::ALERT_LOAN_DUE_DAYS => '5', Settings::ALERT_MAINTENANCE_DAYS => 30,
        Settings::ALERT_INVENTORY_DAYS => 14, Settings::ALERT_DEPRECIATION_MONTHS => 6,
    ]);

    $settings = app(Settings::class);
    expect($settings->int(Settings::ALERT_LOAN_DUE_DAYS))->toBe(5)
        ->and($settings->int(Settings::ALERT_MAINTENANCE_DAYS))->toBe(30)
        ->and($settings->int(Settings::ALERT_INVENTORY_DAYS))->toBe(14)
        ->and($settings->int(Settings::ALERT_DEPRECIATION_MONTHS))->toBe(6)
        ->and(app(AlertGenerator::class))->toBeInstanceOf(AlertGenerator::class);
});

it('valide la durée d\'inactivité entre 5 et 480 minutes (RG-85)', function () {
    $this->actingAs($this->admin);

    foreach ([4, 481, 0, -10, 'x', '30 min', null] as $bad) {
        expect(settingErrors([Settings::SESSION_LIFETIME_MINUTES => $bad]))->toHaveKey(Settings::SESSION_LIFETIME_MINUTES);
    }

    foreach ([5, 480, '45'] as $good) {
        expect(settingErrors([Settings::SESSION_LIFETIME_MINUTES => $good]))->toBe([]);
    }
});

it('borne la taille des documents à la limite de PHP (RG-66)', function () {
    $this->actingAs($this->admin);
    $environment = Mockery::mock(EnvironmentCheck::class)->makePartial();
    $environment->shouldReceive('uploadLimitKb')->andReturn(30 * 1024);
    app()->instance(EnvironmentCheck::class, $environment);

    expect(settingErrors([Settings::DOCUMENT_MAX_SIZE_KB => 31 * 1024]))->toHaveKey(Settings::DOCUMENT_MAX_SIZE_KB)
        ->and(settingErrors([Settings::DOCUMENT_MAX_SIZE_KB => 500]))->toHaveKey(Settings::DOCUMENT_MAX_SIZE_KB)
        ->and(settingErrors([Settings::DOCUMENT_MAX_SIZE_KB => 'beaucoup']))->toHaveKey(Settings::DOCUMENT_MAX_SIZE_KB);

    saveSettings([Settings::DOCUMENT_MAX_SIZE_KB => 25 * 1024]);
    expect(app(Settings::class)->int(Settings::DOCUMENT_MAX_SIZE_KB))->toBe(25 * 1024);
});

it('plafonne la taille des documents à 100 Mo même sans limite PHP', function () {
    $this->actingAs($this->admin);
    $environment = Mockery::mock(EnvironmentCheck::class)->makePartial();
    $environment->shouldReceive('uploadLimitKb')->andReturn(null);
    app()->instance(EnvironmentCheck::class, $environment);

    expect(settingErrors([Settings::DOCUMENT_MAX_SIZE_KB => 101 * 1024]))->toHaveKey(Settings::DOCUMENT_MAX_SIZE_KB)
        ->and(settingErrors([Settings::DOCUMENT_MAX_SIZE_KB => 100 * 1024]))->toBe([]);
});

it('laisse changer le format du numéro tant qu\'aucun bien n\'existe, puis le verrouille (RG-04, Q10)', function () {
    $this->actingAs($this->admin);

    expect(UpdateSettings::numberingLocked())->toBeFalse();
    saveSettings([Settings::NUMBER_FORMAT => Settings::FORMAT_YEARLY, Settings::NUMBER_PREFIX => 'egl']);

    $category = AssetCategory::factory()->create(['prefix' => 'INF']);
    expect(app(Settings::class)->string(Settings::NUMBER_PREFIX))->toBe('EGL')
        ->and(app(AssetNumberGenerator::class)->preview($category))->toBe('EGL-'.now()->year.'-000001');

    Asset::factory()->create(['department_id' => Department::factory()->create()->id]);
    expect(UpdateSettings::numberingLocked())->toBeTrue();

    expect(settingErrors([Settings::NUMBER_FORMAT => Settings::FORMAT_GLOBAL]))->toHaveKey(Settings::NUMBER_FORMAT)
        ->and(settingErrors([Settings::NUMBER_PREFIX => 'ZZZ']))->toHaveKey(Settings::NUMBER_PREFIX)
        ->and(app(Settings::class)->string(Settings::NUMBER_FORMAT))->toBe(Settings::FORMAT_YEARLY)
        ->and(app(Settings::class)->string(Settings::NUMBER_PREFIX))->toBe('EGL');

    // Réenregistrer les mêmes valeurs reste possible (autres onglets).
    saveSettings([Settings::NUMBER_FORMAT => Settings::FORMAT_YEARLY, Settings::NUMBER_PREFIX => 'EGL', Settings::CHURCH_ACRONYM => 'EEE']);
    expect(app(Settings::class)->string(Settings::CHURCH_ACRONYM))->toBe('EEE');
});

it('verrouille la numérotation dès qu\'un compteur a avancé, même sans bien', function () {
    $this->actingAs($this->admin);
    app(AssetNumberGenerator::class)->syncWith('global', 12);

    expect(UpdateSettings::numberingLocked())->toBeTrue()
        ->and(settingErrors([Settings::NUMBER_FORMAT => Settings::FORMAT_CATEGORY]))->toHaveKey(Settings::NUMBER_FORMAT);
});

it('refuse un préfixe ou un format invalide', function () {
    $this->actingAs($this->admin);

    expect(settingErrors([Settings::NUMBER_PREFIX => 'A']))->toHaveKey(Settings::NUMBER_PREFIX)
        ->and(settingErrors([Settings::NUMBER_PREFIX => 'ÉGLISE']))->toHaveKey(Settings::NUMBER_PREFIX)
        ->and(settingErrors([Settings::NUMBER_PREFIX => 'TROPLONG']))->toHaveKey(Settings::NUMBER_PREFIX)
        ->and(settingErrors([Settings::NUMBER_FORMAT => 'aleatoire']))->toHaveKey(Settings::NUMBER_FORMAT);
});

it('grise format et préfixe dans la page quand des biens existent et affiche l\'aperçu', function () {
    $this->actingAs($this->admin);
    Asset::factory()->create(['number' => 'PAT-000001', 'department_id' => Department::factory()->create()->id]);

    livewire(SettingsPage::class)
        ->assertFormFieldIsDisabled(Settings::NUMBER_FORMAT)
        ->assertFormFieldIsDisabled(Settings::NUMBER_PREFIX)
        ->assertSee('Format et préfixe figés');
});

it('applique la durée d\'inactivité enregistrée à la configuration de session', function () {
    config(['session.lifetime' => 30]);

    $this->actingAs($this->admin);
    saveSettings([Settings::SESSION_LIFETIME_MINUTES => 12]);

    // Comme à la première requête réelle : le gestionnaire de session n'existe pas encore quand le middleware passe.
    app('session')->forgetDrivers();

    $response = $this->get('/admin/parametres');
    $response->assertOk();

    $minutes = fn (object $handler): int => (fn (): int => $this->minutes)->call($handler);

    expect(config('session.lifetime'))->toBe(12)
        ->and($minutes(app('session')->driver()->getHandler()))->toBe(12);

    $cookie = collect($response->headers->getCookies())->first(fn ($c) => $c->getName() === config('session.cookie'));
    expect($cookie)->not->toBeNull()
        ->and((int) round(($cookie->getExpiresTime() - time()) / 60))->toBe(12);
});

it('garde SESSION_LIFETIME du fichier .env tant qu\'aucune durée n\'est enregistrée', function () {
    config(['session.lifetime' => 45]);
    $this->actingAs($this->admin);

    $this->get('/admin/parametres')->assertOk();

    expect(config('session.lifetime'))->toBe(45)
        ->and(app(UpdateSettings::class)->current(Settings::SESSION_LIFETIME_MINUTES))->toBe(45);
});

it('journalise avant et après, sans rien enregistrer quand rien ne change (RG-70)', function () {
    $this->actingAs($this->admin);

    saveSettings([Settings::CHURCH_NAME => 'Église A', Settings::ALERT_LOAN_DUE_DAYS => 3]);

    $log = Activity::query()->where('log_name', 'parametres')->latest('id')->first();
    expect($log)->not->toBeNull()
        ->and($log->event)->toBe('settings_updated')
        ->and($log->causer_id)->toBe($this->admin->id)
        ->and($log->properties['attributes'])->toBe([Settings::CHURCH_NAME => 'Église A'])
        ->and($log->properties['old'])->toBe([Settings::CHURCH_NAME => '[Nom de l\'Église]']);

    saveSettings([Settings::CHURCH_NAME => 'Église A']);
    expect(Activity::query()->where('log_name', 'parametres')->count())->toBe(1);

    saveSettings([Settings::SESSION_LIFETIME_MINUTES => 20]);
    $log = Activity::query()->where('log_name', 'parametres')->latest('id')->first();
    expect($log->properties['attributes'])->toBe([Settings::SESSION_LIFETIME_MINUTES => 20])
        ->and(json_encode($log->properties))->not->toContain('password')->not->toContain('secret');
});

it('affiche l\'état du serveur en lecture seule et le lien vers les règles d\'amortissement', function () {
    $this->actingAs($this->admin);
    app(Settings::class)->recordTechnical(Settings::ALERTS_LAST_RUN_AT, now()->subMinutes(10)->toIso8601String());

    $this->get('/admin/parametres')->assertOk()
        ->assertSee('Version de PHP')
        ->assertSee('Espace disque')
        ->assertSee('Planificateur')
        ->assertSee('Dernière sauvegarde')
        ->assertSee('dernier calcul le')
        ->assertSee('/admin/regles-amortissement', false)
        ->assertSee('8 caractères minimum')
        ->assertSee('15 minutes après 5 échecs');
});

it('signale un planificateur arrêté', function () {
    $this->actingAs($this->admin);
    app(Settings::class)->recordTechnical(Settings::ALERTS_LAST_RUN_AT, now()->subHours(5)->toIso8601String());

    expect(app(ServerStatus::class)->scheduler()['status'])->toBe('warn');

    app(RefreshAlerts::class)();
    expect(app(ServerStatus::class)->scheduler()['status'])->toBe('ok');
});

it('n\'écrase pas le réglage d\'un autre administrateur : seuls les champs modifiés partent (mise à jour perdue)', function () {
    $other = User::factory()->role(DefaultRoles::ADMIN)->create();

    $this->actingAs($this->admin);
    $pageA = livewire(SettingsPage::class);
    $this->actingAs($other);
    $pageB = livewire(SettingsPage::class);

    $this->actingAs($this->admin);
    $pageA->fillForm([Settings::CHURCH_ACRONYM => 'AAA'])->callAction('save');

    $this->actingAs($other);
    $pageB->fillForm([Settings::ALERT_LOAN_DUE_DAYS => 9])->callAction('save');

    expect(app(Settings::class)->string(Settings::CHURCH_ACRONYM))->toBe('AAA')
        ->and(app(Settings::class)->int(Settings::ALERT_LOAN_DUE_DAYS))->toBe(9);

    $last = Activity::query()->where('log_name', 'parametres')->latest('id')->first();
    expect($last->causer_id)->toBe($other->id)->and(array_keys($last->properties['attributes']))->toBe([Settings::ALERT_LOAN_DUE_DAYS]);
});

it('refuse de modifier un réglage changé par un autre administrateur depuis le chargement', function () {
    $this->actingAs($this->admin);
    $stale = livewire(SettingsPage::class);

    saveSettings([Settings::CHURCH_ACRONYM => 'AAA']);

    $stale->fillForm([Settings::CHURCH_ACRONYM => 'BBB'])->callAction('save');
    expect(app(Settings::class)->string(Settings::CHURCH_ACRONYM))->toBe('AAA');

    $errors = settingErrorsWithOriginal([Settings::CHURCH_ACRONYM => 'BBB'], [Settings::CHURCH_ACRONYM => '[SIGLE]']);
    expect($errors)->toHaveKey(Settings::CHURCH_ACRONYM);
});

it('ramène un SESSION_LIFETIME du .env hors bornes dans les bornes sans bloquer l\'écran', function () {
    $this->actingAs($this->admin);

    foreach ([600 => 480, 2 => 5] as $env => $shown) {
        config(['session.lifetime' => $env]);

        expect(app(UpdateSettings::class)->current(Settings::SESSION_LIFETIME_MINUTES))->toBe($shown);

        livewire(SettingsPage::class)
            ->assertFormSet([Settings::SESSION_LIFETIME_MINUTES => $shown])
            ->fillForm([Settings::CHURCH_ACRONYM => 'S'.$env])
            ->callAction('save')
            ->assertHasNoFormErrors();

        expect(app(Settings::class)->string(Settings::CHURCH_ACRONYM))->toBe('S'.$env);
    }
});

function settingErrorsWithOriginal(array $data, array $original): array
{
    try {
        app(UpdateSettings::class)($data, $original);
    } catch (ValidationException $exception) {
        return $exception->errors();
    }

    return [];
}
