<?php

use Illuminate\Routing\Exceptions\InvalidSignatureException;
use Illuminate\Session\TokenMismatchException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
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

it('affiche sur la page 403 le message métier en français, sans lire la base ni la session', function () {
    DB::enableQueryLog();

    $this->get('/_t/403-message')
        ->assertForbidden()
        ->assertSee('Seul le comptable peut valider cette sortie.');

    // Seule requête admise : celle de ApplySessionLifetime (E41), qui lit la durée d'inactivité avant le démarrage de la session.
    $queries = DB::getQueryLog();

    expect(count($queries))->toBeLessThanOrEqual(1);

    if ($queries !== []) {
        expect((string) $queries[0]['query'])->toContain('settings');
    }
});

it('affiche quand même la page 403 en français quand la base est injoignable', function () {
    $default = config('database.default');
    config([
        'database.connections.injoignable' => ['driver' => 'sqlite', 'database' => storage_path('inexistant/absent.sqlite'), 'prefix' => '', 'foreign_key_constraints' => false],
        'database.default' => 'injoignable',
    ]);

    try {
        $this->get('/_t/403-message')
            ->assertForbidden()
            ->assertSee('Seul le comptable peut valider cette sortie.');
    } finally {
        config(['database.default' => $default]);
        DB::purge('injoignable');
    }
});

it('n\'affiche pas « Invalid signature. » ni un autre message anglais sur la page 403', function () {
    Route::middleware('web')->get('/_t/signature', fn () => throw new InvalidSignatureException);

    $this->get('/_t/signature')
        ->assertForbidden()
        ->assertDontSee('Invalid signature')
        ->assertSee('Contactez l\'administrateur', false);
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

it('rend la page française 503 en mode maintenance réel', function () {
    Artisan::call('down');

    try {
        $this->get('/admin/login')->assertStatus(503)->assertSee('Maintenance en cours')->assertSee('Réessayer');
    } finally {
        Artisan::call('up');
    }
});

it('répond en français aux erreurs JSON', function () {
    $this->getJson('/_t/500')->assertStatus(500)->assertExactJson(['message' => 'Une erreur est survenue.']);
    $this->getJson('/inconnu-json')->assertNotFound()->assertJson(['message' => 'Ressource introuvable.']);
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
