<?php

namespace App\Actions\Labels;

use App\Models\Activity;
use App\Models\Asset;
use App\Models\LabelTemplate;
use App\Services\Labels\LabelSheetBuilder;
use App\Support\Labels\LabelContent;
use App\Support\Labels\PrintedLabels;
use Barryvdh\DomPDF\Facade\Pdf;
use DomainException;
use Illuminate\Support\Facades\Gate;

/**
 * RG-97 : impression unitaire, en lot ou réimpression, depuis un PDF à l'échelle exacte.
 * RG-70 : chaque impression est inscrite au journal (biens, modèle, support, cases).
 */
class PrintLabels
{
    public const int MAX_LABELS = 500;

    public function __construct(private LabelSheetBuilder $sheets) {}

    /**
     * @param  list<int>  $assetIds  dans l'ordre d'impression ; les biens hors périmètre sont ignorés
     */
    public function __invoke(LabelTemplate $template, array $assetIds, int $copies = 1, int $startCell = 1): PrintedLabels
    {
        Gate::authorize('print', LabelTemplate::class);

        $assets = $this->assets($assetIds);

        if ($assets === []) {
            throw new DomainException('Aucun bien à étiqueter.');
        }

        if (! $this->sheets->fits($template)) {
            throw new DomainException('Ce modèle ne tient pas sur son support : réduisez la largeur ou la hauteur.');
        }

        $copies = max(1, min($copies, 50));
        $labels = [];

        foreach ($assets as $asset) {
            for ($copy = 0; $copy < $copies; $copy++) {
                $labels[] = LabelContent::fromAsset($asset);
            }
        }

        if (count($labels) > self::MAX_LABELS) {
            throw new DomainException('Trop d\'étiquettes d\'un coup (maximum '.self::MAX_LABELS.') : imprimez en plusieurs fois.');
        }

        $pages = $this->sheets->pages($template, $labels, $startCell);
        [$pageWidth, $pageHeight] = $this->sheets->pageSize($template);

        $pdf = Pdf::loadView('pdf.labels', [
            'pages' => $pages,
            'pageWidth' => $pageWidth,
            'pageHeight' => $pageHeight,
        ])
            ->setPaper($template->sheet_layout->isSheet() ? 'a4' : [0, 0, $this->points($pageWidth), $this->points($pageHeight)])
            ->setOption([
                'enable_remote' => false,
                'enable_php' => false,
                'default_font' => 'DejaVu Sans',
                'dpi' => 96,
                'enable_font_subsetting' => true,
            ]);

        $this->log($template, $assets, $copies, count($labels), $startCell);

        return new PrintedLabels(
            pdf: $pdf->output(),
            fileName: 'etiquettes-'.now()->format('Ymd-His').'.pdf',
            labelCount: count($labels),
            pageCount: count($pages),
        );
    }

    /**
     * @param  list<int>  $ids
     * @return list<Asset>
     */
    private function assets(array $ids): array
    {
        $ids = array_values(array_unique($ids));

        $found = Asset::query()
            ->with(['department', 'site', 'room'])
            ->whereIn('id', $ids)
            ->whereNull('disposed_at')
            ->get()
            ->keyBy('id');

        $ordered = [];

        foreach ($ids as $id) {
            if ($found->has($id)) {
                $ordered[] = $found->get($id);
            }
        }

        return $ordered;
    }

    /**
     * @param  list<Asset>  $assets
     */
    private function log(LabelTemplate $template, array $assets, int $copies, int $labelCount, int $startCell): void
    {
        $numbers = array_map(fn (Asset $asset): string => $asset->number, $assets);
        $reprint = $this->alreadyPrinted($numbers);
        $cells = $this->sheets->cellRange($template, $labelCount, $startCell);
        $support = $template->sheet_layout->isSheet()
            ? 'Planche A4'
            : 'Rouleau '.rtrim(rtrim(number_format($template->width_mm, 1, ',', ''), '0'), ',').' × '.rtrim(rtrim(number_format($template->height_mm, 1, ',', ''), '0'), ',').' mm';

        activity('etiquettes')
            ->event('label_printed')
            ->withProperties(['attributes' => array_filter([
                'asset_numbers' => $numbers,
                'template' => $template->name,
                'support' => $support,
                'cells' => $cells,
                'copies' => $copies,
                'label_count' => $labelCount,
                'reprint' => $reprint,
            ], fn (mixed $value): bool => $value !== null)])
            ->log(($reprint ? 'Réimpression' : 'Impression').' d\'étiquettes : '.$labelCount.' étiquette'.($labelCount > 1 ? 's' : '').', '.$support.($cells !== null ? ', '.$cells : ''));
    }

    /**
     * @param  list<string>  $numbers
     */
    private function alreadyPrinted(array $numbers): bool
    {
        foreach (array_chunk($numbers, 50) as $chunk) {
            $found = Activity::query()
                ->where('log_name', 'etiquettes')
                ->where('event', 'label_printed')
                ->where(function ($query) use ($chunk): void {
                    foreach ($chunk as $number) {
                        $query->orWhereJsonContains('properties->attributes->asset_numbers', $number);
                    }
                })
                ->exists();

            if ($found) {
                return true;
            }
        }

        return false;
    }

    private function points(float $mm): float
    {
        return round($mm / 25.4 * 72, 3);
    }
}
