<?php

use App\Models\User;
use App\Support\DefaultRoles;
use Database\Seeders\RolesSeeder;
use Illuminate\Session\TokenMismatchException;
use Illuminate\Support\Facades\Route;
use Symfony\Component\HttpKernel\Exception\HttpException;

beforeEach(function () {
    config(['app.debug' => false]);

    Route::middleware('web')->group(function () {
        Route::get('/_t/403', fn () => abort(403));
        Route::get('/_t/403-message', fn () => abort(403, 'Seul le comptable peut valider cette sortie.'));
        Route::get('/_t/419', fn () => throw new TokenMismatchException);
        Route::get('/_t/429', fn () => abort(429));
        Route::get('/_t/500', fn () => throw new RuntimeException('Détail technique secret'));
        Route::get('/_t/503', fn () => throw new HttpException(503));
    });
});

it('affiche une page 404 en français aux couleurs du thème', function () {
    $this->get('/page-qui-n-existe-pas')
        ->assertNotFound()
        ->assertSee('Cette page n\'existe pas')
        ->assertSee('Retour à mon espace')
        ->assertSee('fonts/fonts.css', false)
        ->assertSee('css/etats-systeme.css', false)
        ->assertDontSee('Not Found');

    expect(file_get_contents(public_path('css/etats-systeme.css')))
        ->toContain('#0B5D6B')
        ->toContain('#F4F6F8')
        ->toContain('border-radius: 12px')
        ->toContain('Martian Mono');
});

it('affiche les pages 403, 419, 429, 500 et 503 en français', function (string $url, int $status, string $titre) {
    $this->get($url)
        ->assertStatus($status)
        ->assertSee($titre)
        ->assertSee('lang="fr"', false);
})->with([
    '403' => ['/_t/403', 403, 'Vous n\'avez pas accès à cette page'],
    '419' => ['/_t/419', 419, 'Votre session a expiré'],
    '429' => ['/_t/429', 429, 'Trop de demandes'],
    '500' => ['/_t/500', 500, 'Une erreur est survenue'],
    '503' => ['/_t/503', 503, 'Maintenance en cours'],
]);

it('ne divulgue aucun détail technique sur la page 500', function () {
    $this->get('/_t/500')
        ->assertStatus(500)
        ->assertDontSee('Détail technique secret')
        ->assertDontSee('RuntimeException')
        ->assertDontSee('Whoops');
});

it('affiche sur la page 403 le rôle de l\'utilisateur connecté et le message métier en français', function () {
    $this->seed(RolesSeeder::class);
    $user = User::factory()->create();
    $user->assignRole(DefaultRoles::DEPARTMENT_HEAD);

    $this->actingAs($user)
        ->get('/_t/403-message')
        ->assertForbidden()
        ->assertSee('Responsable de département')
        ->assertSee('Seul le comptable peut valider cette sortie.');
});

it('n\'affiche pas le message anglais par défaut sur la page 403', function () {
    $this->get('/_t/403')
        ->assertForbidden()
        ->assertSee('Contactez l\'administrateur', false)
        ->assertDontSee('unauthorized', false)
        ->assertDontSee('Forbidden', false);
});

it('ne charge aucune ressource externe sur les pages d\'erreur', function (string $url) {
    $html = $this->get($url)->getContent();

    preg_match_all('/(?:src|href)\s*=\s*["\']([^"\']+)["\']/i', $html, $m);
    $externes = collect($m[1])->filter(fn (string $u) => preg_match('#^(https?:)?//#i', $u) === 1)
        ->reject(fn (string $u) => parse_url($u, PHP_URL_HOST) === parse_url((string) config('app.url'), PHP_URL_HOST));

    expect($externes->all())->toBe([]);
})->with(['/inconnu', '/_t/403', '/_t/419', '/_t/500', '/_t/503']);

it('rend l\'erreur 503 du mode maintenance avec la page française', function () {
    $this->get('/_t/503')->assertStatus(503)->assertSee('Réessayer');
});

it('place les fenêtres d\'état en français dans le panneau, y compris sur la connexion', function () {
    $html = $this->get('/admin/login')->assertOk()->getContent();

    expect($html)
        ->toContain('id="etats-systeme"')
        ->toContain('Connexion au serveur perdue')
        ->toContain('Votre session a expiré')
        ->toContain('js/etats-systeme.js')
        ->toContain('data-sante=');

    $js = file_get_contents(public_path('js/etats-systeme.js'));

    expect($js)->toContain('interceptRequest')
        ->toContain('419')
        ->toContain('onFailure')
        ->not->toContain('http://')
        ->not->toContain('https://');
});
