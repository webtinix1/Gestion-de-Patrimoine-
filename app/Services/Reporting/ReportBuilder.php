<?php

namespace App\Services\Reporting;

use App\Enums\InventoryLineResult;
use App\Models\Acquisition;
use App\Models\Asset;
use App\Models\Disposal;
use App\Models\InventoryLine;
use App\Models\Loan;
use App\Models\LoanItem;
use App\Models\MaintenanceJob;
use App\Models\Movement;
use App\Models\User;
use App\Services\AssetListReport;
use App\Services\DepreciationRegister;
use App\Services\FiscalYearCalendar;
use App\Services\Settings;
use App\Support\Money;
use App\Support\Reports\ReportData;
use App\Support\Reports\ReportDefinition;
use App\Support\Reports\ReportFilters;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use InvalidArgumentException;

/**
 * E29 : construit le contenu des rapports. Toutes les lectures passent par les modèles et leurs scopes de
 * périmètre (RG-81) et par PatrimonyFigures, qui alimente aussi le tableau de bord : mêmes chiffres partout.
 * L'autorisation (droits) se vérifie avant, dans les actions d'aperçu et d'export.
 */
class ReportBuilder
{
    public function __construct(
        private PatrimonyFigures $figures,
        private AssetListReport $assetList,
        private Settings $settings,
    ) {}

    public function build(ReportDefinition $report, ReportFilters $filters, ?User $by = null): ReportData
    {
        $year = $filters->year ?? $this->currentYear();

        [$headings, $rows, $money, $numbers, $footerLeft, $footerRight] = match ($report->key) {
            'general' => $this->general($filters),
            'by_department' => $this->grouped('Département', $this->figures->byDepartment($filters, true), 'départements'),
            'by_site' => $this->bySite($filters, $year),
            'by_category' => $this->grouped('Catégorie', $this->figures->byCategory($filters, true), 'catégories'),
            'by_room' => $this->grouped('Local', $this->figures->byRoom($filters, true), 'locaux'),
            'acquisitions' => $this->acquisitions($filters),
            'movements' => $this->movements($filters),
            'loans' => $this->loans($filters),
            'maintenance' => $this->maintenance($filters),
            'disposals' => $this->disposals($filters),
            'inventory_gaps' => $this->inventoryGaps($filters),
            'depreciation_register', 'depreciation_charges', 'depreciation_accumulated', 'net_book_value', 'fully_depreciated' => $this->depreciation($report->key, $filters, $year),
            default => throw new InvalidArgumentException('Rapport inconnu : '.$report->key),
        };

        $statusLabel = $filters->status === null ? null : ($report->statusOptions()[$filters->status] ?? null);
        $labels = $filters->labels($statusLabel);

        if ($report->usesFiscalYear && $filters->year === null) {
            $labels[] = 'Exercice : '.$year;
        }

        return new ReportData(
            key: $report->key,
            title: $report->label,
            headings: $headings,
            rows: $rows,
            moneyColumns: $money,
            numberColumns: $numbers,
            footerLeft: $footerLeft,
            footerRight: $footerRight,
            filters: $labels,
            churchName: $this->settings->string(Settings::CHURCH_NAME),
            churchAcronym: $this->settings->string(Settings::CHURCH_ACRONYM),
            editedAt: now(),
            editedBy: $by?->fullName() ?? 'Application',
        );
    }

    public function currentYear(): int
    {
        return (new FiscalYearCalendar)->current()->year ?? now()->year;
    }

    /**
     * @return array{0: list<string>, 1: list<list<int|string|null>>, 2: list<int>, 3: list<int>, 4: string, 5: string}
     */
    private function general(ReportFilters $filters): array
    {
        $data = $this->assetList->build($this->figures->activeAssets($filters, true)->orderBy('assets.number'));

        return [
            $data->headings,
            $data->rows,
            [11],
            [],
            $data->count.' biens au patrimoine',
            'Total '.Money::format($data->total).' FCFA',
        ];
    }

    /**
     * @param  list<array{id: int, name: string, count: int, acquisition: int}>  $groups
     * @return array{0: list<string>, 1: list<list<int|string|null>>, 2: list<int>, 3: list<int>, 4: string, 5: string}
     */
    private function grouped(string $heading, array $groups, string $unit): array
    {
        $rows = array_map(fn (array $group): array => [$group['name'], $group['count'], $group['acquisition']], $groups);

        return [
            [$heading, 'Biens', 'Valeur d\'acquisition (FCFA)'],
            $rows,
            [2],
            [1],
            count($groups).' '.$unit.' · '.array_sum(array_column($groups, 'count')).' biens',
            'Total '.Money::format(array_sum(array_column($groups, 'acquisition'))).' FCFA',
        ];
    }

    /**
     * @return array{0: list<string>, 1: list<list<int|string|null>>, 2: list<int>, 3: list<int>, 4: string, 5: string}
     */
    private function bySite(ReportFilters $filters, int $year): array
    {
        $sites = $this->figures->bySite($year, $filters, true);
        $rows = array_map(fn (array $site): array => [$site['name'], $site['count'], $site['acquisition'], $site['net']], $sites);

        return [
            ['Site', 'Biens', 'Valeur d\'acquisition (FCFA)', 'VNC au 31/12/'.$year.' (FCFA)'],
            $rows,
            [2, 3],
            [1],
            count($sites).' sites · '.array_sum(array_column($sites, 'count')).' biens',
            'Total '.Money::format(array_sum(array_column($sites, 'acquisition'))).' FCFA · VNC '.Money::format(array_sum(array_column($sites, 'net'))).' FCFA',
        ];
    }

    /**
     * @return array{0: list<string>, 1: list<list<int|string|null>>, 2: list<int>, 3: list<int>, 4: string, 5: string}
     */
    private function acquisitions(ReportFilters $filters): array
    {
        [$from, $to] = $filters->range();
        $acquisitions = Acquisition::query()
            ->with(['supplier', 'site', 'department'])
            ->when($filters->siteId, fn (Builder $q, int $id) => $q->where('site_id', $id))
            ->when($filters->departmentId, fn (Builder $q, int $id) => $q->where('department_id', $id))
            ->when($filters->categoryId, fn (Builder $q, int $id) => $q->whereHas('lines', fn (Builder $lines) => $lines->where('asset_category_id', $id)))
            ->when($filters->status, fn (Builder $q, string $status) => $q->where('status', $status))
            ->when($from, fn (Builder $q, CarbonImmutable $date) => $q->where('acquired_on', '>=', $date->toDateString()))
            ->when($to, fn (Builder $q, CarbonImmutable $date) => $q->where('acquired_on', '<=', $date->toDateString()))
            ->orderBy('acquired_on')
            ->orderBy('id')
            ->get();

        $rows = $acquisitions->map(fn (Acquisition $a): array => [
            $a->reference,
            $a->acquired_on->format('d/m/Y'),
            $a->supplier->name ?? null,
            $a->invoice_reference,
            $a->site->name ?? null,
            $a->department->name ?? null,
            $a->status->getLabel(),
            $a->total_amount,
        ])->values()->all();

        return [
            ['Référence', 'Date', 'Fournisseur', 'Facture', 'Site', 'Département', 'Statut', 'Montant (FCFA)'],
            $rows,
            [7],
            [],
            $acquisitions->count().' acquisitions',
            'Total '.Money::format((int) $acquisitions->sum('total_amount')).' FCFA',
        ];
    }

    /**
     * @return array{0: list<string>, 1: list<list<int|string|null>>, 2: list<int>, 3: list<int>, 4: string, 5: string}
     */
    private function movements(ReportFilters $filters): array
    {
        [$from, $to] = $filters->range();
        $movements = Movement::query()
            ->with(['asset', 'fromSite', 'fromDepartment', 'fromService', 'fromRoom', 'fromHolder', 'toSite', 'toDepartment', 'toService', 'toRoom', 'toHolder'])
            ->whereHas('asset', fn (Builder $asset) => $filters->applyToAssets($asset, withStatus: false))
            ->when($from, fn (Builder $q, CarbonImmutable $date) => $q->where('moved_on', '>=', $date->toDateString()))
            ->when($to, fn (Builder $q, CarbonImmutable $date) => $q->where('moved_on', '<=', $date->toDateString()))
            ->orderByDesc('moved_on')
            ->orderByDesc('id')
            ->get();

        $rows = $movements->map(fn (Movement $m): array => [
            $m->moved_on->format('d/m/Y'),
            $m->asset->number,
            $m->asset->label(),
            $m->type->getLabel(),
            $m->assignmentLabel('from'),
            $m->assignmentLabel('to'),
            $m->reason,
        ])->values()->all();

        return [['Date', 'N° patrimonial', 'Désignation', 'Type', 'De', 'Vers', 'Motif'], $rows, [], [], $movements->count().' mouvements', ''];
    }

    /**
     * @return array{0: list<string>, 1: list<list<int|string|null>>, 2: list<int>, 3: list<int>, 4: string, 5: string}
     */
    private function loans(ReportFilters $filters): array
    {
        [$from, $to] = $filters->range();
        $hasAssetFilter = $filters->siteId || $filters->departmentId || $filters->serviceId || $filters->categoryId || $filters->holderId;
        $loans = Loan::query()
            ->with(['items.asset'])
            ->when($hasAssetFilter, fn (Builder $q) => $q->whereHas('items', fn (Builder $items) => $items->whereHas('asset', fn (Builder $asset) => $filters->applyToAssets($asset, withStatus: false))))
            ->when($filters->status, fn (Builder $q, string $status) => $q->where('status', $status))
            ->when($from, fn (Builder $q, CarbonImmutable $date) => $q->where('out_on', '>=', $date->toDateString()))
            ->when($to, fn (Builder $q, CarbonImmutable $date) => $q->where('out_on', '<=', $date->toDateString()))
            ->orderByDesc('out_on')
            ->orderByDesc('id')
            ->get();

        $rows = $loans->map(function (Loan $loan): array {
            $labels = $loan->items->map(fn (LoanItem $item): string => $item->label())->values();

            return [
                $loan->reference,
                $loan->direction->getLabel(),
                $loan->borrower_name,
                $labels->take(3)->join(' ; ').($labels->count() > 3 ? ' (+'.($labels->count() - 3).')' : ''),
                $loan->out_on->format('d/m/Y'),
                $loan->due_on->format('d/m/Y'),
                $loan->returned_on?->format('d/m/Y'),
                $loan->situationLabel(),
            ];
        })->values()->all();

        return [
            ['Référence', 'Sens', 'Bénéficiaire / prêteur', 'Biens', 'Sortie', 'Retour prévu', 'Retour effectif', 'Situation'],
            $rows,
            [],
            [],
            $loans->count().' prêts et emprunts · '.$loans->filter(fn (Loan $loan): bool => $loan->isOverdue())->count().' en retard',
            '',
        ];
    }

    /**
     * @return array{0: list<string>, 1: list<list<int|string|null>>, 2: list<int>, 3: list<int>, 4: string, 5: string}
     */
    private function maintenance(ReportFilters $filters): array
    {
        [$from, $to] = $filters->range();
        $jobs = MaintenanceJob::query()
            ->with('asset')
            ->whereHas('asset', fn (Builder $asset) => $filters->applyToAssets($asset, withStatus: false))
            ->when($filters->status, fn (Builder $q, string $status) => $q->where('status', $status))
            ->when($from, fn (Builder $q, CarbonImmutable $date) => $q->where('starts_on', '>=', $date->toDateString()))
            ->when($to, fn (Builder $q, CarbonImmutable $date) => $q->where('starts_on', '<=', $date->toDateString()))
            ->orderByDesc('starts_on')
            ->orderByDesc('id')
            ->get();

        $rows = $jobs->map(fn (MaintenanceJob $job): array => [
            $job->reference,
            $job->asset->number,
            $job->asset->label(),
            $job->nature->getLabel(),
            $job->starts_on->format('d/m/Y'),
            $job->ends_on?->format('d/m/Y'),
            $job->provider_name,
            $job->cost,
            $job->result?->getLabel(),
            $job->status->getLabel(),
        ])->values()->all();

        return [
            ['Référence', 'N° patrimonial', 'Désignation', 'Nature', 'Début', 'Fin', 'Prestataire', 'Coût (FCFA)', 'Résultat', 'Statut'],
            $rows,
            [7],
            [],
            $jobs->count().' interventions',
            'Coût total '.Money::format((int) $jobs->sum('cost')).' FCFA',
        ];
    }

    /**
     * @return array{0: list<string>, 1: list<list<int|string|null>>, 2: list<int>, 3: list<int>, 4: string, 5: string}
     */
    private function disposals(ReportFilters $filters): array
    {
        [$from, $to] = $filters->range();
        $disposals = Disposal::query()
            ->with('asset')
            ->whereHas('asset', fn (Builder $asset) => $filters->applyToAssets($asset, withStatus: false))
            ->when($filters->status, fn (Builder $q, string $status) => $q->where('accounting_status', $status))
            ->when($from, fn (Builder $q, CarbonImmutable $date) => $q->where('disposed_on', '>=', $date->toDateString()))
            ->when($to, fn (Builder $q, CarbonImmutable $date) => $q->where('disposed_on', '<=', $date->toDateString()))
            ->orderByDesc('disposed_on')
            ->orderByDesc('id')
            ->get();

        $rows = $disposals->map(fn (Disposal $d): array => [
            $d->reference,
            $d->disposed_on->format('d/m/Y'),
            $d->asset->number,
            $d->asset->label(),
            $d->reason->getLabel(),
            $d->original_value,
            $d->accumulated_depreciation,
            $d->net_book_value,
            $d->sale_price,
            $d->accounting_status->getLabel(),
        ])->values()->all();

        return [
            ['Référence', 'Date', 'N° patrimonial', 'Désignation', 'Motif', 'Valeur d\'origine', 'Amortissements cumulés', 'VNC à la sortie', 'Prix de cession', 'Statut comptable'],
            $rows,
            [5, 6, 7, 8],
            [],
            $disposals->count().' sorties',
            'VNC sortie '.Money::format((int) $disposals->sum('net_book_value')).' FCFA',
        ];
    }

    /**
     * @return array{0: list<string>, 1: list<list<int|string|null>>, 2: list<int>, 3: list<int>, 4: string, 5: string}
     */
    private function inventoryGaps(ReportFilters $filters): array
    {
        [$from, $to] = $filters->range();
        $hasAssetFilter = $filters->siteId || $filters->departmentId || $filters->categoryId;
        $results = $filters->status !== null && InventoryLineResult::tryFrom($filters->status)?->isDiscrepancy()
            ? [$filters->status]
            : InventoryLineResult::discrepancyValues();

        $lines = InventoryLine::query()
            ->with(['inventory', 'asset', 'expectedDepartment', 'expectedRoom', 'foundDepartment', 'foundRoom', 'foundSite', 'expectedSite'])
            ->whereIn('result', $results)
            ->when($hasAssetFilter, fn (Builder $q) => $q->where(fn (Builder $any) => $any
                ->whereHas('asset', fn (Builder $asset) => $filters->applyToAssets($asset, withStatus: false))
                // Un bien trouvé mais non enregistré n'a pas de fiche : on le rattache au lieu constaté.
                ->orWhere(fn (Builder $unregistered) => $unregistered->whereNull('asset_id')
                    ->when($filters->siteId, fn (Builder $x, int $id) => $x->where('found_site_id', $id))
                    ->when($filters->departmentId, fn (Builder $x, int $id) => $x->where('found_department_id', $id))
                    ->when($filters->categoryId, fn (Builder $x) => $x->whereRaw('1 = 0')))))
            ->whereHas('inventory', fn (Builder $inventory) => $inventory
                ->when($from, fn (Builder $q, CarbonImmutable $date) => $q->where('starts_on', '>=', $date->toDateString()))
                ->when($to, fn (Builder $q, CarbonImmutable $date) => $q->where('starts_on', '<=', $date->toDateString())))
            ->orderBy('inventory_id')
            ->orderBy('id')
            ->get();

        $place = fn (?object $department, ?object $room): string => trim(($department->name ?? '—').($room ? ' · '.$room->name : ''));

        $rows = $lines->map(fn (InventoryLine $line): array => [
            $line->inventory->reference,
            $line->asset->number ?? null,
            $line->asset?->label() ?? $line->description,
            $line->result->getLabel(),
            $line->expected ? $place($line->expectedDepartment, $line->expectedRoom) : null,
            $line->result === InventoryLineResult::Missing ? null : $place($line->foundDepartment, $line->foundRoom),
            $line->resolution?->getLabel() ?? 'À régulariser',
        ])->values()->all();

        $open = $lines->filter(fn (InventoryLine $line): bool => $line->resolution === null)->count();

        return [
            ['Inventaire', 'N° patrimonial', 'Désignation', 'Résultat', 'Attendu', 'Constaté', 'Régularisation'],
            $rows,
            [],
            [],
            $lines->count().' écarts',
            $open.' à régulariser',
        ];
    }

    /**
     * @return array{0: list<string>, 1: list<list<int|string|null>>, 2: list<int>, 3: list<int>, 4: string, 5: string}
     */
    private function depreciation(string $key, ReportFilters $filters, int $year): array
    {
        $summary = $this->figures->depreciation($year, $filters);
        $totals = $summary['totals'];

        if ($key === 'depreciation_charges') {
            $rows = array_map(fn (array $category): array => [$category['name'], $category['count'], $category['original'], $category['amount']], $summary['categories']);

            return [
                ['Catégorie', 'Biens', 'Valeur d\'origine (FCFA)', 'Dotations '.$year.' (FCFA)'],
                $rows,
                [2, 3],
                [1],
                $totals['count'].' immobilisations amortissables',
                'Dotations '.$year.' : '.Money::format($totals['amount']).' FCFA',
            ];
        }

        $assets = $this->figures->depreciationQuery($year, $filters)->with('category')->orderBy('assets.number')->get()
            ->filter(fn (Asset $asset): bool => $asset->getAttribute('schedule_id') !== null && $asset->getAttribute('accumulated_to_year') !== null)
            ->values();

        $entries = $assets->map(function (Asset $asset) use ($year): array {
            $held = DepreciationRegister::heldAtYearEnd($asset->disposed_at, $year);
            $original = (int) $asset->getAttribute('schedule_original_value');
            $accumulated = (int) $asset->getAttribute('accumulated_to_year');

            return [
                'asset' => $asset,
                'original' => $original,
                'amount' => (int) ($asset->getAttribute('year_amount') ?? 0),
                'accumulated' => $accumulated,
                'net' => $original - $accumulated,
                'ends' => (int) $asset->getAttribute('last_year'),
                'held' => $held,
                'year' => $year,
            ];
        });

        $identity = fn (array $entry): array => [$entry['asset']->number, $entry['asset']->designation, $entry['asset']->category->name ?? null];

        return match ($key) {
            'depreciation_accumulated' => [
                // Bien sorti dans l'exercice : exclu des cumuls et de la VNC (RG-52, cas A5).
                ['N° patrimonial', 'Désignation', 'Catégorie', 'Valeur d\'origine (FCFA)', 'Amortissements cumulés au 31/12/'.$year.' (FCFA)'],
                $entries->filter(fn (array $e): bool => $e['held'])->map(fn (array $e): array => [...$identity($e), $e['original'], $e['accumulated']])->values()->all(),
                [3, 4],
                [],
                $totals['count'].' immobilisations',
                'Cumul '.Money::format($totals['accumulated']).' FCFA',
            ],
            'net_book_value' => [
                ['N° patrimonial', 'Désignation', 'Catégorie', 'Valeur d\'origine (FCFA)', 'VNC au 31/12/'.$year.' (FCFA)'],
                $entries->filter(fn (array $e): bool => $e['held'])->map(fn (array $e): array => [...$identity($e), $e['original'], $e['net']])->values()->all(),
                [3, 4],
                [],
                $totals['count'].' immobilisations',
                'VNC totale '.Money::format($totals['net']).' FCFA',
            ],
            'fully_depreciated' => $this->fullyDepreciated($entries->filter(fn (array $e): bool => $e['held'] && $e['ends'] <= $year)->values()->all(), $identity),
            default => [
                ['N° patrimonial', 'Désignation', 'Catégorie', 'Mise en service', 'Valeur d\'origine (FCFA)', 'Dotation '.$year.' (FCFA)', 'Cumul (FCFA)', 'VNC (FCFA)'],
                // Un bien sorti dans l'exercice garde sa dotation jusqu'à la sortie, sans cumul ni VNC.
                $entries->map(fn (array $e): array => [...$identity($e), $e['asset']->in_service_on?->format('d/m/Y'), $e['original'], $e['amount'], $e['held'] ? $e['accumulated'] : null, $e['held'] ? $e['net'] : null])->all(),
                [4, 5, 6, 7],
                [],
                $totals['count'].' immobilisations amortissables',
                'VNC totale '.Money::format($totals['net']).' FCFA',
            ],
        };
    }

    /**
     * @param  list<array{asset: Asset, original: int, amount: int, accumulated: int, net: int, ends: int, year: int, held: bool}>  $entries
     * @return array{0: list<string>, 1: list<list<int|string|null>>, 2: list<int>, 3: list<int>, 4: string, 5: string}
     */
    private function fullyDepreciated(array $entries, callable $identity): array
    {
        $rows = array_map(fn (array $e): array => [...$identity($e), $e['asset']->in_service_on?->format('d/m/Y'), $e['original'], (string) $e['ends']], $entries);

        return [
            ['N° patrimonial', 'Désignation', 'Catégorie', 'Mise en service', 'Valeur d\'origine (FCFA)', 'Dernier exercice d\'amortissement'],
            $rows,
            [4],
            [],
            count($entries).' immobilisations totalement amorties',
            'Valeur d\'origine '.Money::format((int) array_sum(array_column($entries, 'original'))).' FCFA',
        ];
    }
}
