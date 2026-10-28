<?php

use App\Actions\Labels\PrintLabels;
use App\Enums\LabelSheetLayout;
use App\Models\Asset;
use App\Models\LabelTemplate;
use App\Models\User;
use App\Services\Labels\LabelComposer;
use App\Services\Labels\LabelSheetBuilder;
use App\Support\DefaultRoles;
use App\Support\Labels\LabelContent;
use Tests\Support\PdfInspector;

function labelContents(int $count): array
{
    return array_map(fn (int $n): LabelContent => new LabelContent('PAT-'.str_pad((string) $n, 6, '0', STR_PAD_LEFT), 'Bien '.$n, 'Finances'), range(1, $count));
}

beforeEach(function () {
    $this->actingAs(User::factory()->role(DefaultRoles::MANAGER)->create());
    $this->builder = app(LabelSheetBuilder::class);
    $this->sheet = LabelTemplate::factory()->create(['name' => 'Standard']);
    $this->roll = LabelTemplate::factory()->roll()->create(['name' => 'Rouleau']);
});

it('place 24 étiquettes de 70 × 37 mm sur une planche A4 3 × 8, sans marge latérale (RG-96)', function () {
    $pages = $this->builder->pages($this->sheet, labelContents(24));

    expect($pages)->toHaveCount(1)->and($pages[0])->toHaveCount(24);

    $first = $pages[0][0];
    $last = $pages[0][23];

    expect([$first->xMm, $first->yMm, $first->widthMm, $first->heightMm])->toBe([0.0, 0.5, 70.0, 37.0])
        ->and([$pages[0][1]->xMm, $pages[0][1]->yMm])->toBe([70.0, 0.5])
        ->and([$pages[0][3]->xMm, $pages[0][3]->yMm])->toBe([0.0, 37.5])
        ->and([$last->xMm, $last->yMm])->toBe([140.0, 259.5])
        ->and($last->xMm + $last->widthMm)->toBe(210.0)
        ->and($last->yMm + $last->heightMm)->toBe(296.5);
});

it('commence une planche entamée à la case demandée (RG-96)', function () {
    $pages = $this->builder->pages($this->sheet, labelContents(8), startCell: 5);
    $filled = collect($pages[0])->filter(fn ($slot) => $slot->label !== null)->pluck('number')->all();

    expect($pages)->toHaveCount(1)
        ->and($filled)->toBe([5, 6, 7, 8, 9, 10, 11, 12])
        ->and($pages[0][3]->label)->toBeNull()
        ->and($pages[0][4]->label)->not->toBeNull()
        ->and([$pages[0][4]->xMm, $pages[0][4]->yMm])->toBe([70.0, 37.5]);
});

it('passe à une deuxième planche quand la première est pleine, la case de départ ne valant que pour la première', function () {
    $pages = $this->builder->pages($this->sheet, labelContents(22), startCell: 5);

    expect($pages)->toHaveCount(2)
        ->and(collect($pages[0])->filter(fn ($slot) => $slot->label !== null))->toHaveCount(20)
        ->and(collect($pages[1])->filter(fn ($slot) => $slot->label !== null)->pluck('number')->all())->toBe([1, 2]);
});

it('refuse une case de départ hors de la planche', function (int $cell) {
    $this->builder->pages($this->sheet, labelContents(1), startCell: $cell);
})->with([0, 25, -3])->throws(InvalidArgumentException::class);

it('décrit les cases occupées pour le journal et l\'aperçu', function () {
    expect($this->builder->cellRange($this->sheet, 8, 5))->toBe('cases 5 à 12')
        ->and($this->builder->cellRange($this->sheet, 1, 7))->toBe('case 7')
        ->and($this->builder->cellRange($this->sheet, 30, 5))->toBe('à partir de la case 5, sur 2 planches')
        ->and($this->builder->cellRange($this->roll, 8, 1))->toBeNull();
});

it('met chaque étiquette de rouleau sur sa page, à la taille exacte de 50 × 25 mm', function () {
    $pages = $this->builder->pages($this->roll, labelContents(3), startCell: 9);

    expect($pages)->toHaveCount(3)
        ->and($pages[0][0]->label)->not->toBeNull()
        ->and([$pages[0][0]->xMm, $pages[0][0]->yMm, $pages[0][0]->widthMm, $pages[0][0]->heightMm])->toBe([0.0, 0.0, 50.0, 25.0])
        ->and($this->builder->pageSize($this->roll))->toBe([50.0, 25.0]);
});

it('vérifie qu\'un modèle tient sur son support', function () {
    $tooWide = LabelTemplate::factory()->make(['width_mm' => 80, 'height_mm' => 37]);
    $tooTall = LabelTemplate::factory()->make(['width_mm' => 70, 'height_mm' => 40]);
    $twoByFourteen = LabelTemplate::factory()->make(['sheet_layout' => LabelSheetLayout::A4TwoBySeven, 'width_mm' => 99.1, 'height_mm' => 38.1]);

    expect($this->builder->fits($this->sheet))->toBeTrue()
        ->and($this->builder->fits($tooWide))->toBeFalse()
        ->and($this->builder->fits($tooTall))->toBeFalse()
        ->and($this->builder->fits($twoByFourteen))->toBeTrue()
        ->and($this->builder->fits($this->roll))->toBeTrue();
});

it('génère un PDF A4 dont chaque code est posé à la case prévue, à l\'échelle exacte', function () {
    $assets = Asset::factory()->count(3)->sequence(
        ['number' => 'PAT-000501'], ['number' => 'PAT-000502'], ['number' => 'PAT-000503'],
    )->create();

    $printed = app(PrintLabels::class)($this->sheet, $assets->pluck('id')->all(), startCell: 5);
    $pdf = new PdfInspector($printed->pdf);

    expect($printed->pdf)->toStartWith('%PDF')
        ->and($printed->labelCount)->toBe(3)
        ->and($printed->pageCount)->toBe(1)
        ->and($pdf->pageCount())->toBe(1)
        ->and($pdf->pageSizesMm())->toBe([[210.0, 297.0]]);

    $images = $pdf->imagesByPage()[0];

    expect($images)->toHaveCount(3);

    // Cases 5, 6 et 7. Le QR (29,4 mm) est à 1 mm du bord gauche et centré en hauteur dans la case de 37 mm.
    $origins = [[70.0, 37.5], [140.0, 37.5], [0.0, 74.5]];

    foreach ($images as $index => $image) {
        expect($image['w'])->toEqualWithDelta(29.4, 0.05)
            ->and($image['h'])->toEqualWithDelta(29.4, 0.05)
            ->and($image['x'])->toEqualWithDelta($origins[$index][0] + 1.0, 0.05)
            ->and($image['y'])->toEqualWithDelta($origins[$index][1] + 3.8, 0.05);
    }

    $numbers = array_values(array_filter($pdf->texts(), fn (string $text): bool => str_starts_with($text, 'PAT-')));

    expect($numbers)->toBe(['PAT-000501', 'PAT-000502', 'PAT-000503']);
});

it('met la 25e étiquette en haut à gauche de la deuxième planche (case 24 + 1)', function () {
    $assets = Asset::factory()->count(2)->create();

    $printed = app(PrintLabels::class)($this->sheet, $assets->pluck('id')->all(), startCell: 24);
    $pdf = new PdfInspector($printed->pdf);
    $pages = $pdf->imagesByPage();

    expect($printed->pageCount)->toBe(2)
        ->and($pdf->pageSizesMm())->toBe([[210.0, 297.0], [210.0, 297.0]])
        ->and($pages[0])->toHaveCount(1)
        ->and($pages[0][0]['x'])->toEqualWithDelta(141.0, 0.05)
        ->and($pages[0][0]['y'])->toEqualWithDelta(259.5 + 3.8, 0.05)
        ->and($pages[1])->toHaveCount(1)
        ->and($pages[1][0]['x'])->toEqualWithDelta(1.0, 0.05)
        ->and($pages[1][0]['y'])->toEqualWithDelta(0.5 + 3.8, 0.05);
});

it('refuse une case de départ hors de la planche dans l\'action, avec un message clair', function (int $cell) {
    app(PrintLabels::class)($this->sheet, [Asset::factory()->create()->id], startCell: $cell);
})->with([0, 25, 99])->throws(DomainException::class, 'entre 1 et 24');

it('génère un PDF de rouleau dont chaque page fait exactement 50 × 25 mm', function () {
    $assets = Asset::factory()->count(2)->sequence(['number' => 'PAT-000501'], ['number' => 'PAT-000502'])->create();

    $printed = app(PrintLabels::class)($this->roll, $assets->pluck('id')->all());
    $pdf = new PdfInspector($printed->pdf);

    expect($printed->pageCount)->toBe(2)
        ->and($pdf->pageCount())->toBe(2)
        ->and($pdf->pageSizesMm())->toBe([[50.0, 25.0], [50.0, 25.0]]);

    // Code 128 de 123 modules de 0,25 mm = 30,75 mm, centré, 9 mm de haut, à 2 mm du bas.
    foreach ($pdf->imagesByPage() as $images) {
        expect($images)->toHaveCount(1)
            ->and($images[0]['w'])->toEqualWithDelta(30.75, 0.05)
            ->and($images[0]['h'])->toEqualWithDelta(9.0, 0.05)
            ->and($images[0]['x'])->toEqualWithDelta(9.625, 0.05)
            ->and($images[0]['y'])->toEqualWithDelta(14.0, 0.05);
    }

    $numbers = array_values(array_filter($pdf->texts(), fn (string $text): bool => str_starts_with($text, 'PAT-')));

    expect($numbers)->toBe(['PAT-000501', 'PAT-000502']);
});

it('imprime autant d\'étiquettes que de copies demandées, sur plusieurs planches si besoin', function () {
    $assets = Asset::factory()->count(13)->create();

    $printed = app(PrintLabels::class)($this->sheet, $assets->pluck('id')->all(), copies: 2);
    $pdf = new PdfInspector($printed->pdf);
    $pages = $pdf->imagesByPage();

    expect($printed->labelCount)->toBe(26)
        ->and($printed->pageCount)->toBe(2)
        ->and($pdf->pageCount())->toBe(2)
        ->and($pdf->pageSizesMm())->toBe([[210.0, 297.0], [210.0, 297.0]])
        ->and($pages[0])->toHaveCount(24)
        ->and($pages[1])->toHaveCount(2)
        ->and($pages[1][0]['x'])->toEqualWithDelta(1.0, 0.05)
        ->and($pages[1][1]['x'])->toEqualWithDelta(71.0, 0.05)
        ->and($pages[1][1]['y'])->toEqualWithDelta(0.5 + 3.8, 0.05);
});

it('imprime en entier un numéro de 15 caractères sur la planche, le rouleau QR et le plus petit modèle accepté', function (string $name, array $state) {
    $template = LabelTemplate::factory()->create(['name' => $name, ...$state]);
    $asset = Asset::factory()->create(['number' => 'PAT-2026-000125']);

    app(LabelComposer::class)->assertCanPrint($template);

    $pdf = new PdfInspector(app(PrintLabels::class)($template, [$asset->id])->pdf);

    expect($pdf->texts())->toContain('PAT-2026-000125');
})->with([
    'planche 70 × 37 QR' => ['Standard long', []],
    'rouleau 50 × 25 QR' => ['Rouleau long', ['width_mm' => 50, 'height_mm' => 25, 'sheet_layout' => LabelSheetLayout::Roll]],
    'plus petit modèle accepté 40 × 20' => ['Petit', ['width_mm' => 40, 'height_mm' => 20, 'sheet_layout' => LabelSheetLayout::Roll]],
]);

it('refuse un modèle qui dépasse la page', function () {
    $tooWide = LabelTemplate::factory()->create(['width_mm' => 80]);
    $asset = Asset::factory()->create();

    app(PrintLabels::class)($tooWide, [$asset->id]);
})->throws(DomainException::class, 'ne tient pas');

it('refuse une impression sans bien', function () {
    app(PrintLabels::class)($this->sheet, []);
})->throws(DomainException::class, 'Aucun bien');

it('refuse plus de 500 étiquettes d\'un coup, avant de produire le PDF', function () {
    $assets = Asset::factory()->count(11)->create();

    app(PrintLabels::class)($this->sheet, $assets->pluck('id')->all(), copies: 50);
})->throws(DomainException::class, 'Trop d\'étiquettes');

it('produit 500 étiquettes en moins de 60 secondes (poste de développement)', function () {
    $assets = Asset::factory()->count(10)->create();

    $start = microtime(true);
    $printed = app(PrintLabels::class)($this->sheet, $assets->pluck('id')->all(), copies: 50);
    $seconds = microtime(true) - $start;

    fwrite(STDERR, sprintf("\n[durée 10 biens × 50 copies, planche QR : %.1f s, pic mémoire %.0f Mo]\n", $seconds, memory_get_peak_usage(true) / 1048576));

    expect($printed->labelCount)->toBe(500)->and($seconds)->toBeLessThan(60.0);
});

it('produit 200 étiquettes de rouleau (la limite) en moins de 60 secondes', function () {
    $assets = Asset::factory()->count(4)->create();

    $start = microtime(true);
    $printed = app(PrintLabels::class)($this->roll, $assets->pluck('id')->all(), copies: 50);
    $seconds = microtime(true) - $start;

    fwrite(STDERR, sprintf("\n[durée 4 biens × 50 copies, rouleau Code 128 : %.1f s, pic mémoire %.0f Mo]\n", $seconds, memory_get_peak_usage(true) / 1048576));

    expect($printed->pageCount)->toBe(200)->and($seconds)->toBeLessThan(60.0);
});

it('limite un rouleau à 200 étiquettes par PDF', function () {
    $assets = Asset::factory()->count(5)->create();

    app(PrintLabels::class)($this->roll, $assets->pluck('id')->all(), copies: 50);
})->throws(DomainException::class, 'maximum 200');
