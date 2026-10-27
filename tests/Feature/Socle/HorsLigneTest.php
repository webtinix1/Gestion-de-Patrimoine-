<?php

use App\Models\User;
use Illuminate\Support\Facades\File;

/**
 * Renvoie les URL externes chargées par une page HTML (src, href, url(), @import).
 *
 * @return list<string>
 */
function externalResources(string $html): array
{
    preg_match_all(
        '/(?:src|href|srcset|action)\s*=\s*["\']([^"\']+)["\']|url\(\s*["\']?([^"\')]+)["\']?\s*\)|@import\s+["\']([^"\']+)["\']/i',
        $html,
        $matches,
    );

    $appHost = parse_url((string) config('app.url'), PHP_URL_HOST);

    return collect(array_merge($matches[1], $matches[2], $matches[3]))
        ->filter()
        ->filter(fn (string $url): bool => preg_match('#^(https?:)?//#i', $url) === 1)
        ->reject(fn (string $url): bool => parse_url(str_starts_with($url, '//') ? 'http:'.$url : $url, PHP_URL_HOST) === $appHost)
        ->values()
        ->all();
}

it('ne charge aucune ressource externe sur la page de connexion', function () {
    $html = $this->get('/admin/login')->assertOk()->getContent();

    expect(externalResources($html))->toBe([]);
});

it('ne charge aucune ressource externe sur le tableau de bord', function () {
    $html = $this->actingAs(User::factory()->create())
        ->get('/admin')
        ->assertOk()
        ->getContent();

    expect(externalResources($html))->toBe([])
        ->and($html)->not->toContain('ui-avatars.com')
        ->not->toContain('fonts.bunny.net')
        ->not->toContain('fonts.googleapis.com');
});

it('ne référence aucun CDN dans le code de l\'application', function () {
    $forbidden = ['fonts.bunny.net', 'fonts.googleapis.com', 'fonts.gstatic.com', 'cdn.jsdelivr.net', 'unpkg.com', 'cdnjs.cloudflare.com', 'ui-avatars.com'];

    $files = collect(['app', 'config', 'resources', 'routes', 'public/fonts', 'public/build'])
        ->filter(fn (string $dir) => is_dir(base_path($dir)))
        ->flatMap(fn (string $dir) => File::allFiles(base_path($dir)))
        ->filter(fn (SplFileInfo $file) => in_array($file->getExtension(), ['php', 'js', 'css', 'html', 'json'], true));

    foreach ($files as $file) {
        $content = file_get_contents($file->getPathname());

        foreach ($forbidden as $host) {
            expect(str_contains($content, $host))
                ->toBeFalse("{$host} trouvé dans {$file->getRelativePathname()}");
        }
    }
});
