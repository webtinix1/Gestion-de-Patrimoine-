<?php

use App\Enums\LabelCodeType;
use App\Models\Asset;
use App\Models\LabelTemplate;
use App\Services\Labels\LabelCodeRenderer;
use App\Services\Labels\LabelComposer;
use App\Services\Settings;
use App\Support\Labels\LabelContent;
use Tests\Support\CodeReader;

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

it('génère un QR code que la lecture décode en numéro seul', function (string $number) {
    $code = app(LabelCodeRenderer::class)->render(LabelCodeType::Qr, $number, 30, 30);

    expect(CodeReader::qr(CodeReader::bytes($code->uri)))->toBe($number)
        ->and($code->widthMm)->toBe(30.0)
        ->and($code->heightMm)->toBe(30.0);
})->with(['PAT-000125', 'PAT-2026-000125', 'INF-000007', 'AUD-2026-009999']);

it('garde 4 modules de silence autour du QR code, dans l\'image', function () {
    $code = app(LabelCodeRenderer::class)->render(LabelCodeType::Qr, 'PAT-000125', 30, 30);
    $image = imagecreatefromstring(CodeReader::bytes($code->uri));
    $width = imagesx($image);

    // PAT-000125 : version 1 (21 modules) + 2 × 4 modules de silence.
    $module = $width / 29;
    $firstDark = null;

    for ($x = 0; $x < $width && $firstDark === null; $x++) {
        if (imagecolorsforindex($image, imagecolorat($image, $x, (int) ($module * 7.5)))['red'] < 128) {
            $firstDark = $x;
        }
    }

    expect($width % 29)->toBe(0)
        ->and(imagesy($image))->toBe($width)
        ->and($firstDark)->not->toBeNull()
        ->and($firstDark)->toBe((int) round($module * 4));
});

it('génère un Code 128 que la lecture décode en numéro seul, somme de contrôle comprise', function (string $number) {
    $code = app(LabelCodeRenderer::class)->render(LabelCodeType::Code128, $number, 60, 9);
    $read = CodeReader::code128(CodeReader::bytes($code->uri));

    expect($read['text'])->toBe($number)
        ->and($read['checksum_ok'])->toBeTrue()
        ->and($code->heightMm)->toBe(9.0);
})->with(['PAT-000125', 'PAT-2026-000125', 'INF-000007']);

it('donne au Code 128 un module multiple de 0,125 mm et 10 modules de silence de chaque côté', function (float $available) {
    $code = app(LabelCodeRenderer::class)->render(LabelCodeType::Code128, 'PAT-000125', $available, 9);
    $modules = CodeReader::code128(CodeReader::bytes($code->uri))['modules'];
    $moduleMm = $code->widthMm / $modules;

    expect(fmod(round($moduleMm / 0.125, 6), 1.0))->toBe(0.0)
        ->and($moduleMm)->toBeGreaterThanOrEqual(0.25)
        ->and(($available - $code->widthMm) / 2)->toBeGreaterThanOrEqual(10 * $moduleMm - 0.0001);
})->with([46.0, 70.0, 36.0]);

it('refuse un Code 128 qui ne tient pas avec sa zone de silence plutôt que de le rendre illisible', function () {
    app(LabelCodeRenderer::class)->render(LabelCodeType::Code128, 'PAT-2026-000125', 46, 9);
})->throws(DomainException::class, 'ne tient pas');

it('calcule chaque image une seule fois par numéro, quel que soit le nombre de copies', function () {
    $renderer = app(LabelCodeRenderer::class);

    $first = $renderer->render(LabelCodeType::Qr, 'PAT-000125', 30, 30);

    expect($renderer->render(LabelCodeType::Qr, 'PAT-000125', 30, 30))->toBe($first)
        ->and($renderer->render(LabelCodeType::Qr, 'PAT-000126', 30, 30))->not->toBe($first);
});

it('n\'utilise aucune ressource externe : les images sont des données locales', function () {
    $renderer = app(LabelCodeRenderer::class);

    foreach ([LabelCodeType::Qr, LabelCodeType::Code128] as $type) {
        foreach ([false, true] as $vector) {
            $uri = $renderer->render($type, 'PAT-000125', 46, 10, $vector)->uri;

            expect($uri)->toStartWith('data:image/')->and($uri)->not->toContain('http');
        }
    }
});

it('imprime le sigle, le numéro et le code sur chaque étiquette (RG-95)', function () {
    app(Settings::class)->set(Settings::CHURCH_ACRONYM, 'EPC');
    $template = LabelTemplate::factory()->make(['show_designation' => false, 'show_department' => false]);

    $label = app(LabelComposer::class)->compose($template, new LabelContent('PAT-000125', 'Ordinateur portable HP', 'Finances'));

    expect(array_column($label->lines, 'text'))->toBe(['EPC', 'PAT-000125'])
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

it('imprime un numéro long en entier, sans le tronquer, en réduisant la taille', function (string $number) {
    $template = LabelTemplate::factory()->make();

    $label = app(LabelComposer::class)->compose($template, new LabelContent($number, 'Chaise', 'Finances'));
    $line = collect($label->lines)->firstWhere('mono', true);

    expect($line['text'])->toBe($number)
        ->and($line['pt'])->toBeGreaterThanOrEqual(6.0)
        ->and($line['pt'])->toBeLessThanOrEqual(11.0)
        // 0,602 em de large par caractère en DejaVu Sans Mono : la ligne doit tenir dans sa boîte.
        ->and(mb_strlen($number) * 0.602 * $line['pt'] * 0.3528)->toBeLessThanOrEqual($line['w'] + 0.0001);
})->with(['PAT-2026-000001', 'INF-2026-000007', 'PAT-000125']);

it('refuse un modèle sur lequel le numéro ne tient pas, plutôt que de le couper', function () {
    $tiny = LabelTemplate::factory()->make(['width_mm' => 20, 'height_mm' => 15]);

    app(LabelComposer::class)->compose($tiny, new LabelContent('PAT-2026-000001'));
})->throws(DomainException::class, 'ne tient pas');

it('vérifie un modèle avec le plus long numéro du format configuré', function () {
    $composer = app(LabelComposer::class);
    $standard = LabelTemplate::factory()->make();
    $roll = LabelTemplate::factory()->roll()->make();

    expect($composer->longestNumber())->toBe('PAT-000001');
    $composer->assertCanPrint($standard);
    $composer->assertCanPrint($roll);

    app(Settings::class)->set(Settings::NUMBER_FORMAT, Settings::FORMAT_YEARLY);
    $composer = app(LabelComposer::class);

    expect($composer->longestNumber())->toBe('PAT-2026-000001');
    $composer->assertCanPrint($standard);
    $composer->assertCanPrint($roll);

    // Un rouleau de 40 mm ne tient pas pour le Code 128 d'un numéro avec année.
    expect(fn () => $composer->assertCanPrint(LabelTemplate::factory()->roll()->make(['width_mm' => 40])))->toThrow(DomainException::class);
});

it('tire le contenu de l\'étiquette de la fiche du bien', function () {
    $asset = Asset::factory()->create(['number' => 'PAT-000125', 'designation' => 'Vidéoprojecteur', 'brand' => 'Epson']);

    $content = LabelContent::fromAsset($asset->load(['department', 'site', 'room']));

    expect($content->number)->toBe('PAT-000125')
        ->and($content->designation)->toBe('Vidéoprojecteur Epson')
        ->and($content->department)->toBe($asset->department->name);
});
