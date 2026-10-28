<?php

namespace App\Services\Reporting;

use App\Enums\AssetStatus;
use App\Models\Asset;
use App\Models\AssetCategory;
use App\Models\Department;
use App\Models\Room;
use App\Models\Site;
use App\Services\DepreciationRegister;
use App\Support\Reports\ReportFilters;
use Illuminate\Database\Eloquent\Builder;

/**
 * Source unique des chiffres du patrimoine : le tableau de bord (E02, E42) et les rapports (E29) les lisent ici,
 * ils ne peuvent donc pas diverger. Tout passe par le scope global de Asset : seul le périmètre de l'utilisateur
 * connecté est compté (RG-81).
 */
class PatrimonyFigures
{
    public function __construct(private DepreciationRegister $register) {}

    /**
     * Biens au patrimoine : tout bien qui n'a pas fait l'objet d'une sortie (RG-50).
     *
     * @return Builder<Asset>
     */
    public function activeAssets(?ReportFilters $filters = null, bool $withPeriod = false): Builder
    {
        $query = Asset::query()->whereNull('assets.disposed_at');

        return ($filters ?? new ReportFilters)->applyToAssets($query, $withPeriod);
    }

    /**
     * @return array{count: int, acquisition: int}
     */
    public function activeTotals(?ReportFilters $filters = null, bool $withPeriod = false): array
    {
        $row = $this->activeAssets($filters, $withPeriod)
            ->toBase()
            ->selectRaw('count(*) as total, coalesce(sum(assets.acquisition_cost), 0) as cost')
            ->first();

        return ['count' => (int) ($row->total ?? 0), 'acquisition' => (int) ($row->cost ?? 0)];
    }

    /**
     * Nombre de biens par statut, biens sortis compris (statuts « cédé » et « réformé »).
     *
     * @return array<string, int>
     */
    public function statusCounts(): array
    {
        $counts = array_fill_keys(array_map(fn (AssetStatus $status): string => $status->value, AssetStatus::cases()), 0);

        foreach (Asset::query()->toBase()->selectRaw('assets.status, count(*) as total')->groupBy('assets.status')->get() as $row) {
            $counts[(string) $row->status] = (int) $row->total;
        }

        return $counts;
    }

    /**
     * @return list<array{id: int, name: string, count: int, acquisition: int}>
     */
    public function byDepartment(?ReportFilters $filters = null, bool $withPeriod = false): array
    {
        return $this->grouped('department_id', Department::class, $filters, $withPeriod);
    }

    /**
     * @return list<array{id: int, name: string, count: int, acquisition: int}>
     */
    public function byCategory(?ReportFilters $filters = null, bool $withPeriod = false): array
    {
        return $this->grouped('asset_category_id', AssetCategory::class, $filters, $withPeriod);
    }

    /**
     * @return list<array{id: int, name: string, count: int, acquisition: int}>
     */
    public function byRoom(?ReportFilters $filters = null, bool $withPeriod = false): array
    {
        $rows = $this->grouped('room_id', Room::class, $filters, $withPeriod, includeNull: true);

        return array_map(fn (array $row): array => $row['id'] === 0 ? [...$row, 'name' => 'Sans local'] : $row, $rows);
    }

    /**
     * Sites avec nombre de biens, valeur d'acquisition et valeur nette comptable à la fin de l'exercice.
     *
     * @return list<array{id: int, name: string, count: int, acquisition: int, net: int}>
     */
    public function bySite(int $year, ?ReportFilters $filters = null, bool $withPeriod = false): array
    {
        $rows = $this->grouped('site_id', Site::class, $filters, $withPeriod);
        $net = $this->netBookValueBySite($year, $filters);

        return array_map(fn (array $row): array => [...$row, 'net' => $net[$row['id']] ?? 0], $rows);
    }

    /**
     * @return array<int, int> valeur nette comptable par site
     */
    public function netBookValueBySite(int $year, ?ReportFilters $filters = null): array
    {
        $query = ($filters ?? new ReportFilters)->applyToAssets($this->register->query($year));
        $net = [];

        foreach ($query->toBase()->cursor() as $row) {
            $row = (array) $row;

            if ($row['schedule_id'] === null || $row['accumulated_to_year'] === null) {
                continue;
            }

            $site = (int) $row['site_id'];
            $net[$site] = ($net[$site] ?? 0) + (int) $row['schedule_original_value'] - (int) $row['accumulated_to_year'];
        }

        return $net;
    }

    /**
     * Valeurs d'amortissement de l'exercice : mêmes totaux que le tableau des immobilisations (E30).
     *
     * @return array{totals: array{count: int, original: int, amount: int, accumulated: int, net: int, fully: int, ending: int, current: int, none: int}, categories: list<array{name: string, duration: int|null, count: int, original: int, amount: int, accumulated: int, net: int}>}
     */
    public function depreciation(int $year, ?ReportFilters $filters = null): array
    {
        return $this->register->summary($this->depreciationQuery($year, $filters), $year);
    }

    /**
     * @return Builder<Asset>
     */
    public function depreciationQuery(int $year, ?ReportFilters $filters = null): Builder
    {
        return ($filters ?? new ReportFilters)->applyToAssets($this->register->query($year));
    }

    /**
     * @param  class-string<\Illuminate\Database\Eloquent\Model>  $model
     * @return list<array{id: int, name: string, count: int, acquisition: int}>
     */
    private function grouped(string $column, string $model, ?ReportFilters $filters, bool $withPeriod, bool $includeNull = false): array
    {
        $query = $this->activeAssets($filters, $withPeriod)->toBase();

        if (! $includeNull) {
            $query->whereNotNull('assets.'.$column);
        }

        $rows = $query
            ->selectRaw('assets.'.$column.' as group_id, count(*) as total, coalesce(sum(assets.acquisition_cost), 0) as cost')
            ->groupBy('assets.'.$column)
            ->get();

        $names = $model::query()->withoutGlobalScopes()->whereKey($rows->pluck('group_id')->filter()->all())->pluck('name', 'id');

        return $rows
            ->map(fn (object $row): array => [
                'id' => (int) $row->group_id,
                'name' => (string) ($names[$row->group_id] ?? '—'),
                'count' => (int) $row->total,
                'acquisition' => (int) $row->cost,
            ])
            ->sortBy([['name', 'asc']])
            ->values()
            ->all();
    }
}
