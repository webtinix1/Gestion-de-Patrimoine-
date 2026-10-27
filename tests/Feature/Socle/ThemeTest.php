<?php

use App\Filament\AvatarProviders\InitialsAvatarProvider;
use App\Models\User;
use Filament\Facades\Filament;
use Filament\FontProviders\LocalFontProvider;
use Filament\Support\Facades\FilamentColor;
use Filament\Support\View\Components\ButtonComponent;

beforeEach(function () {
    $this->panel = Filament::getPanel('admin');
});

it('applique les couleurs de docs/04', function () {
    $colors = $this->panel->getColors();

    expect($colors['primary'][600])->toBe('#0B5D6B')
        ->and($colors['accent'][400])->toBe('#F2B544')
        ->and([$colors['success'][50], $colors['success'][600]])->toBe(['#E4F2EA', '#1E6E43'])
        ->and([$colors['warning'][50], $colors['warning'][600]])->toBe(['#FBF0D9', '#8A5A00'])
        ->and([$colors['danger'][50], $colors['danger'][600]])->toBe(['#FBE7E5', '#A8261B'])
        ->and([$colors['info'][50], $colors['info'][600]])->toBe(['#E6EBF8', '#2F4A9E']);
});

it('donne aux boutons principaux la couleur primaire exacte, texte blanc', function () {
    $this->actingAs(User::factory()->create())->get('/admin');

    $map = ButtonComponent::make()->getColorMap(FilamentColor::getColor('primary'));

    expect($map['bg'])->toBe(600);
});

it('utilise Archivo et Martian Mono servies localement', function () {
    expect($this->panel->getFontFamily())->toBe('Archivo')
        ->and($this->panel->getFontProvider())->toBe(LocalFontProvider::class)
        ->and($this->panel->getMonoFontFamily())->toBe('Martian Mono')
        ->and($this->panel->getMonoFontProvider())->toBe(LocalFontProvider::class)
        ->and($this->panel->getFontUrl())->toBe(asset('fonts/fonts.css'));
});

it('livre les fichiers de polices dans public/fonts', function (string $file) {
    expect(public_path("fonts/{$file}"))->toBeFile();
})->with([
    'fonts.css',
    'archivo/archivo-latin-wght-normal.woff2',
    'archivo/archivo-latin-ext-wght-normal.woff2',
    'martian-mono/martian-mono-latin-400-normal.woff2',
    'martian-mono/martian-mono-latin-700-normal.woff2',
]);

it('déclare les polices dans fonts.css avec des chemins relatifs', function () {
    $css = file_get_contents(public_path('fonts/fonts.css'));

    expect($css)->toContain("font-family: 'Archivo'")
        ->toContain("font-family: 'Martian Mono'");

    preg_match_all('/url\(([^)]+)\)/', $css, $matches);

    expect($matches[1])->not->toBeEmpty();

    foreach ($matches[1] as $url) {
        expect(public_path('fonts/'.trim($url, '\'"')))->toBeFile();
    }
});

it('déclare les groupes de navigation de docs/04 dans l\'ordre', function () {
    $groups = collect($this->panel->getNavigationGroups())
        ->map(fn ($group) => is_string($group) ? $group : $group->getLabel())
        ->values()
        ->all();

    expect($groups)->toBe([
        'Accueil',
        'Patrimoine',
        'Opérations',
        'Immobilisations',
        'Pilotage',
        'Structure',
        'Administration',
    ]);
});

it('génère les avatars localement, sans service en ligne', function () {
    $user = User::factory()->make(['first_name' => 'Ève', 'last_name' => 'Ngoma']);

    $avatar = app(InitialsAvatarProvider::class)->get($user);

    expect($avatar)->toStartWith('data:image/svg+xml;base64,')
        ->and(base64_decode(substr($avatar, strlen('data:image/svg+xml;base64,'))))->toContain('ÈN');
});
