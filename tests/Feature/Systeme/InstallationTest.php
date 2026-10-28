<?php

use App\Models\AssetCategory;
use App\Models\User;
use App\Services\System\EnvironmentCheck;
use App\Support\DefaultRoles;
use Database\Seeders\DemoSeeder;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Role;

const QUESTION_MOT_DE_PASSE = 'Mot de passe (12 caractères minimum, lettres et chiffres ; non affiché)';

beforeEach(function () {
    config(['app.key' => 'base64:'.base64_encode(random_bytes(32))]);
});

it('liste les extensions de composer.json et celles du pilote de base', function () {
    $extensions = app(EnvironmentCheck::class)->requiredExtensions();

    expect($extensions)->toContain('gd', 'iconv', 'intl', 'zip', 'mbstring', 'bcmath', 'pdo_sqlite')
        ->and(app(EnvironmentCheck::class)->php()['status'])->toBe('ok');
});

it('signale un contrôle bloquant quand la clé est absente', function () {
    config(['app.key' => '']);

    $this->artisan('patrimoine:verifier', ['--sans-base' => true])
        ->expectsOutputToContain('BLOQUANT')
        ->assertFailed();
});

it('affiche un contrôle conforme', function () {
    $this->artisan('patrimoine:verifier')
        ->expectsOutputToContain('Installation conforme')
        ->assertSuccessful();
});

it('installe, crée le premier administrateur et charge les référentiels sans démonstration', function () {
    $this->artisan('patrimoine:installer')
        ->expectsQuestion('Identifiant de l\'administrateur', 'a.moukala')
        ->expectsQuestion('Prénom', 'Alice')
        ->expectsQuestion('Nom', 'Moukala')
        ->expectsQuestion(QUESTION_MOT_DE_PASSE, 'Secret-Fort-2026')
        ->expectsQuestion('Confirmez le mot de passe', 'Secret-Fort-2026')
        ->doesntExpectOutputToContain('Secret-Fort-2026')
        ->expectsOutputToContain('Installation terminée')
        ->assertSuccessful();

    $admin = User::query()->where('username', 'a.moukala')->firstOrFail();

    expect(Hash::check('Secret-Fort-2026', $admin->password))->toBeTrue()
        ->and($admin->password)->not->toContain('Secret')
        ->and($admin->hasRole(DefaultRoles::ADMIN))->toBeTrue()
        ->and($admin->is_active)->toBeTrue()
        ->and($admin->must_change_password)->toBeFalse()
        ->and(Role::query()->count())->toBe(count(DefaultRoles::names()))
        ->and(AssetCategory::query()->count())->toBeGreaterThan(0)
        ->and(User::query()->count())->toBe(1);
});

it('est relançable sans dégât : aucun second administrateur, référentiels inchangés', function () {
    $this->artisan('patrimoine:installer', ['--identifiant' => 'admin1', '--prenom' => 'A', '--nom' => 'B'])
        ->expectsQuestion(QUESTION_MOT_DE_PASSE, 'Secret-Fort-2026')
        ->expectsQuestion('Confirmez le mot de passe', 'Secret-Fort-2026')
        ->assertSuccessful();

    $categories = AssetCategory::query()->count();
    $roles = Role::query()->count();
    AssetCategory::query()->where('prefix', 'MOB')->update(['default_duration_years' => 12]);

    $this->artisan('patrimoine:installer')
        ->expectsOutputToContain('Un administrateur existe déjà')
        ->assertSuccessful();

    expect(User::query()->count())->toBe(1)
        ->and(AssetCategory::query()->count())->toBe($categories)
        ->and(Role::query()->count())->toBe($roles)
        ->and(AssetCategory::query()->where('prefix', 'MOB')->value('default_duration_years'))->toBe(12);
});

it('refuse un mot de passe faible et ne crée aucun compte', function () {
    $this->artisan('patrimoine:installer', ['--identifiant' => 'admin1', '--prenom' => 'A', '--nom' => 'B'])
        ->expectsQuestion(QUESTION_MOT_DE_PASSE, 'court1')
        ->expectsQuestion(QUESTION_MOT_DE_PASSE, 'uniquementdeslettres')
        ->expectsQuestion(QUESTION_MOT_DE_PASSE, '123456789012')
        ->expectsOutputToContain('aucun compte créé')
        ->assertFailed();

    expect(User::query()->count())->toBe(0);
});

it('refuse deux saisies différentes du mot de passe', function () {
    $this->artisan('patrimoine:installer', ['--identifiant' => 'admin1', '--prenom' => 'A', '--nom' => 'B'])
        ->expectsQuestion(QUESTION_MOT_DE_PASSE, 'Secret-Fort-2026')
        ->expectsQuestion('Confirmez le mot de passe', 'Autre-Secret-2026')
        ->expectsQuestion(QUESTION_MOT_DE_PASSE, 'Secret-Fort-2026')
        ->expectsQuestion('Confirmez le mot de passe', 'Secret-Fort-2026')
        ->assertSuccessful();

    expect(User::query()->count())->toBe(1);
});

it('refuse un identifiant invalide ou déjà pris', function () {
    $this->artisan('patrimoine:installer', ['--identifiant' => 'Admin Un', '--prenom' => 'A', '--nom' => 'B'])
        ->expectsOutputToContain('minuscules')
        ->assertFailed();

    expect(User::query()->count())->toBe(0);
});

it('ne demande jamais de mot de passe sans terminal et sans --mot-de-passe-stdin', function () {
    $this->artisan('patrimoine:installer', ['--no-interaction' => true])
        ->expectsOutputToContain('--mot-de-passe-stdin')
        ->assertFailed();

    expect(User::query()->count())->toBe(0);
});

it('charge la démonstration sur option, sans demander de mot de passe', function () {
    $this->artisan('patrimoine:installer', ['--demo' => true])
        ->expectsOutputToContain('Démonstration chargée')
        ->assertSuccessful();

    expect(User::query()->where('username', DemoSeeder::ADMIN_USERNAME)->exists())->toBeTrue();
});

it('refuse la démonstration en production sans --force', function () {
    $this->app['env'] = 'production';

    $this->artisan('patrimoine:installer', ['--demo' => true])
        ->expectsOutputToContain('refusée quand APP_ENV vaut production')
        ->assertFailed();

    expect(User::query()->count())->toBe(0);
});

it('refuse la démonstration sur une base qui contient déjà des comptes réels', function () {
    User::factory()->create(['username' => 'compte.reel']);

    $this->artisan('patrimoine:installer', ['--demo' => true])
        ->expectsOutputToContain('comptes réels')
        ->assertFailed();

    expect(User::query()->where('username', DemoSeeder::ADMIN_USERNAME)->exists())->toBeFalse();
});
