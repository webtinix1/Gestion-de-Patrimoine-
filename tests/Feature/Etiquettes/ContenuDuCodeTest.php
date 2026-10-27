<?php

use App\Enums\LabelCodeType;
use App\Models\Asset;
use App\Models\LabelTemplate;
use App\Services\Labels\LabelCodeRenderer;
use App\Services\Labels\LabelComposer;
use App\Services\Settings;
use App\Support\Labels\LabelContent;

function pngBytes(string $dataUri): string
{
    expect($dataUri)->toStartWith('data:image/png;base64,');

    return base64_decode(substr($dataUri, strlen('data:image/png;base64,')));
}

it('ne met dans le code que le numéro patrimonial (RG-95)', function () {
    $renderer = app(LabelCodeRenderer::class);

    expect($renderer->payload('PAT-000125'))->toBe('PAT-000125')
        ->and($renderer->payload('  INF-2026-000007 '))->toBe('INF-2026-000007');
});

it('refuse tout texte qui n\'est pas un numéro patrimonial (pas d\'adresse, pas de désignation)', function (string $text) {
    app(LabelCodeRenderer::class)->payload($text);
})->with([
    'adresse' => 'http://serveur/admin/biens/12',
    'désignation' => 'PAT-000125 Ordinateur portable',
    'vide' => '',
])->throws(InvalidArgumentException::class);

it('génère un QR code identique pour le même numéro et différent pour un autre contenu', function () {
    $renderer = app(LabelCodeRenderer::class);

    $a = $renderer->render(LabelCodeType::Qr, 'PAT-000125', 30, 30);
    $b = $renderer->render(LabelCodeType::Qr, 'PAT-000125', 30, 30);
    $other = $renderer->render(LabelCodeType::Qr, 'PAT-000126', 30, 30);

    expect(pngBytes($a->uri))->toBe(pngBytes($b->uri))
        ->and(pngBytes($a->uri))->not->toBe(pngBytes($other->uri))
        ->and($a->widthMm)->toBe(30.0)
        ->and($a->heightMm)->toBe(30.0);
});

it('génère un Code 128 dont les barres ne codent que le numéro', function () {
    $renderer = app(LabelCodeRenderer::class);

    $code = $renderer->render(LabelCodeType::Code128, 'PAT-000125', 46, 9);
    $longer = $renderer->render(LabelCodeType::Code128, 'PAT-000125-X', 46, 9);

    expect(pngBytes($code->uri))->toStartWith("\x89PNG")
        ->and($code->heightMm)->toBe(9.0)
        ->and($code->widthMm)->toBeLessThanOrEqual(46 - 2 * 10 * 0.2)
        ->and(pngBytes($code->uri))->not->toBe(pngBytes($longer->uri));

    // 123 modules (Code 128) pour PAT-000125 : la largeur est un nombre entier de pixels à 600 dpi.
    $pixels = getimagesizefromstring(pngBytes($code->uri));
    expect($pixels[0] % 123)->toBe(0)
        ->and(round($pixels[0] / 600 * 25.4, 2))->toBe(round($code->widthMm, 2));
});

it('n\'utilise aucune ressource externe : les images sont des données locales', function () {
    $renderer = app(LabelCodeRenderer::class);

    foreach ([LabelCodeType::Qr, LabelCodeType::Code128] as $type) {
        foreach ([false, true] as $vector) {
            $uri = $renderer->render($type, 'PAT-000125', 30, 10, $vector)->uri;

            expect($uri)->toStartWith('data:image/')->and($uri)->not->toContain('http');
        }
    }
});

it('imprime le sigle, le numéro et le code sur chaque étiquette (RG-95)', function () {
    app(Settings::class)->set(Settings::CHURCH_ACRONYM, 'EPC');
    $template = LabelTemplate::factory()->make(['show_designation' => false, 'show_department' => false]);

    $label = app(LabelComposer::class)->compose($template, new LabelContent('PAT-000125', 'Ordinateur portable HP', 'Finances'));

    $texts = array_column($label->lines, 'text');

    expect($texts)->toBe(['EPC', 'PAT-000125'])
        ->and($label->code->uri)->toStartWith('data:image/png');
});

it('préfère le texte d\'en-tête du modèle au sigle des paramètres', function () {
    $template = LabelTemplate::factory()->make(['header_text' => 'ÉGLISE · PATRIMOINE']);

    $label = app(LabelComposer::class)->compose($template, new LabelContent('PAT-000125'));

    expect($label->lines[0]['text'])->toBe('ÉGLISE · PATRIMOINE');
});

it('affiche les mentions facultatives selon le modèle, sans jamais les mettre dans le code', function () {
    $template = LabelTemplate::factory()->make([
        'show_designation' => true,
        'show_department' => true,
        'show_site' => true,
        'show_room' => true,
    ]);
    $content = new LabelContent('PAT-000125', 'Chaise', 'Finances', 'Siège', 'Bureau');

    $texts = array_column(app(LabelComposer::class)->compose($template, $content)->lines, 'text');

    expect($texts)->toContain('Chaise', 'Département : Finances', 'Site : Siège', 'Local : Bureau');
});

it('retire les mentions de moindre priorité quand l\'étiquette est trop petite, jamais le numéro', function () {
    $template = LabelTemplate::factory()->roll()->make([
        'show_designation' => true,
        'show_department' => true,
        'show_site' => true,
        'show_room' => true,
    ]);

    $label = app(LabelComposer::class)->compose($template, new LabelContent('PAT-000125', 'Chaise', 'Finances', 'Siège', 'Bureau'));
    $texts = array_column($label->lines, 'text');

    expect($texts)->toContain('PAT-000125')->and(count($texts))->toBeLessThan(6);

    foreach ($label->lines as $line) {
        expect($line['y'] + $line['h'])->toBeLessThanOrEqual($label->codeY + 0.001);
    }
});

it('tire le contenu de l\'étiquette de la fiche du bien', function () {
    $asset = Asset::factory()->create(['number' => 'PAT-000125', 'designation' => 'Vidéoprojecteur', 'brand' => 'Epson']);

    $content = LabelContent::fromAsset($asset->load(['department', 'site', 'room']));

    expect($content->number)->toBe('PAT-000125')
        ->and($content->designation)->toBe('Vidéoprojecteur Epson')
        ->and($content->department)->toBe($asset->department->name);
});
