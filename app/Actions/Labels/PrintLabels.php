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
    /** Planche : 500 étiquettes en 10 à 25 s sur le poste de développement (voir docs/08). */
    public const int MAX_LABELS = 500;

    public const int MAX_COPIES = 50;

    /** Un rouleau produit une page par étiquette, nettement plus lente à composer qu'une planche. */
    public const int MAX_LABELS_ROLL = 200;

    private const int MEMORY_BYTES = 402653184;

    public function __construct(private LabelSheetBuilder $sheets) {}

    public static function maxLabels(LabelTemplate $template): int
    {
        return $template->sheet_layout->isSheet() ? self::MAX_LABELS : self::MAX_LABELS_ROLL;
    }

    /**
     * @param  list<int>  $assetIds  dans l'ordre d'impression ; les biens hors périmètre ou sortis sont ignorés
     *
     * @throws DomainException
     */
    public function __invoke(LabelTemplate $template, array $assetIds, int $copies = 1, int $startCell = 1): PrintedLabels
    {
        Gate::authorize('print', LabelTemplate::class);

        $perPage = $this->sheets->perPage($template);

        if ($template->sheet_layout->isSheet() && ($startCell < 1 || $startCell > $perPage)) {
            throw new DomainException("La case de départ doit être comprise entre 1 et {$perPage}.");
        }

        $assets = $this->assets($assetIds);

        if ($assets === []) {
            throw new DomainException('Aucun bien à étiqueter.');
        }

        if (! $this->sheets->fits($template)) {
            throw new DomainException('Ce modèle ne tient pas sur son support : réduisez la largeur ou la hauteur.');
        }

        $copies = max(1, min($copies, self::MAX_COPIES));

        $max = self::maxLabels($template);

        if (count($assets) * $copies > $max) {
            throw new DomainException('Trop d\'étiquettes d\'un coup (maximum '.$max.' pour ce support) : imprimez en plusieurs fois.');
        }

        $labels = [];

        foreach ($assets as $asset) {
            $content = LabelContent::fromAsset($asset);

            for ($copy = 0; $copy < $copies; $copy++) {
                $labels[] = $content;
            }
        }

        // La génération des PDF volumineux peut dépasser les 30 s par défaut de PHP.
        if (PHP_SAPI !== 'cli') {
            @set_time_limit(180);
        }

        $this->ensureMemory();

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

        // Le PDF est produit d'abord : une impression qui échoue ne laisse aucune trace au journal.
        $output = $pdf->output();

        $this->log($template, $assets, $copies, count($labels), $startCell);

        return new PrintedLabels(
            pdf: $output,
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
     * Une entrée par impression. Avec un seul bien, elle est rattachée à sa fiche (onglet Journal).
     *
     * @param  list<Asset>  $assets
     */
    private function log(LabelTemplate $template, array $assets, int $copies, int $labelCount, int $startCell): void
    {
        $numbers = array_map(fn (Asset $asset): string => $asset->number, $assets);
        $reprinted = $this->alreadyPrinted($numbers);
        $cells = $this->sheets->cellRange($template, $labelCount, $startCell);
        $support = $template->sheet_layout->isSheet()
            ? 'Planche A4'
            : 'Rouleau '.rtrim(rtrim(number_format($template->width_mm, 1, ',', ''), '0'), ',').' × '.rtrim(rtrim(number_format($template->height_mm, 1, ',', ''), '0'), ',').' mm';

        $verb = match (true) {
            $reprinted === [] => 'Impression',
            count($reprinted) === count($numbers) => 'Réimpression',
            default => 'Impression',
        };
        $partial = $reprinted !== [] && count($reprinted) < count($numbers)
            ? ' (dont '.count($reprinted).' réimpression'.(count($reprinted) > 1 ? 's' : '').')'
            : '';

        $logger = activity('etiquettes')->event('label_printed');

        if (count($assets) === 1) {
            $logger->performedOn($assets[0]);
        }

        $logger
            ->withProperties(['attributes' => array_filter([
                'asset_numbers' => $numbers,
                'template' => $template->name,
                'support' => $support,
                'cells' => $cells,
                'copies' => $copies,
                'label_count' => $labelCount,
                'reprinted_numbers' => $reprinted === [] ? null : $reprinted,
            ], fn (mixed $value): bool => $value !== null)])
            ->log($verb.' d\'étiquettes : '.$labelCount.' étiquette'.($labelCount > 1 ? 's' : '').', '.$support.($cells !== null ? ', '.$cells : '').$partial);
    }

    /**
     * Les numéros de cette liste dont une étiquette a déjà été imprimée.
     *
     * @param  list<string>  $numbers
     * @return list<string>
     */
    private function alreadyPrinted(array $numbers): array
    {
        $found = [];

        foreach (array_chunk($numbers, 50) as $chunk) {
            $entries = Activity::query()
                ->where('log_name', 'etiquettes')
                ->where('event', 'label_printed')
                ->where(function ($query) use ($chunk): void {
                    foreach ($chunk as $number) {
                        $query->orWhereJsonContains('properties->attributes->asset_numbers', $number);
                    }
                })
                ->get(['properties']);

            foreach ($entries as $entry) {
                $printed = (array) data_get($entry->properties->all(), 'attributes.asset_numbers', []);
                $found = [...$found, ...array_intersect($chunk, $printed)];
            }
        }

        return array_values(array_intersect($numbers, array_unique($found)));
    }

    /**
     * Le memory_limit de 128 Mo d'Apache sous WAMP est juste pour 500 étiquettes : on le relève pour cette requête.
     */
    private function ensureMemory(): void
    {
        $limit = trim((string) ini_get('memory_limit'));

        if ($limit === '-1' || $limit === '') {
            return;
        }

        $bytes = (int) $limit * match (strtoupper(substr($limit, -1))) {
            'G' => 1024 ** 3,
            'M' => 1024 ** 2,
            'K' => 1024,
            default => 1,
        };

        if ($bytes < self::MEMORY_BYTES) {
            @ini_set('memory_limit', (string) self::MEMORY_BYTES);
        }
    }

    private function points(float $mm): float
    {
        return round($mm / 25.4 * 72, 3);
    }
}
