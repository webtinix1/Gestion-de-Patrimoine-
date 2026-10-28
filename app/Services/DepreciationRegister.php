<?php

namespace App\Services;

use App\Models\Asset;
use App\Models\AssetCategory;
use App\Models\DepreciationLine;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Database\Query\JoinClause;

/**
 * Tableau des immobilisations d'un exercice (E30) et totaux des exercices (E33).
 * Les biens passent par le périmètre de l'utilisateur (scope global de Asset, RG-81).
 */
class DepreciationRegister
{
    public const string TAB_CURRENT = 'current';

    public const string TAB_ENDING = 'ending';

    public const string TAB_FULLY = 'fully';

    public const string TAB_NONE = 'none';

    /**
     * @var array<string, string>
     */
    public const array TABS = [
        self::TAB_CURRENT => 'En cours d\'amortissement',
        self::TAB_ENDING => 'Échéance atteinte',
        self::TAB_FULLY => 'Totalement amorties',
        self::TAB_NONE => 'Non amortissables',
    ];

    /**
     * Biens au patrimoine pendant l'exercice, avec leur plan courant et les valeurs de l'exercice.
     *
     * @return Builder<Asset>
     */
    public function query(int $year): Builder
    {
        $line = fn (): Builder => DepreciationLine::query()->whereColumn('depreciation_lines.depreciation_schedule_id', 'ds.id');

        return Asset::query()
            ->select('assets.*')
            ->leftJoin('depreciation_schedules as ds', fn (JoinClause $join) => $join
                ->on('ds.asset_id', '=', 'assets.id')
                ->where('ds.is_current', true))
            ->addSelect([
                'ds.id as schedule_id',
                'ds.base_amount as schedule_base',
                'ds.original_value as schedule_original_value',
                'ds.method as schedule_method',
                'year_amount' => $line()->select('amount')->where('fiscal_year', $year)->limit(1),
                'accumulated_to_year' => $line()->selectRaw('max(accumulated)')->where('fiscal_year', '<=', $year),
                'last_year' => $line()->selectRaw('max(fiscal_year)'),
            ])
            ->where(fn (Builder $query) => $query->whereNull('assets.disposed_at')->orWhere('assets.disposed_at', '>=', $year.'-01-01'));
    }

    /**
     * RG-39, RG-52, cas A5 : un bien sorti au plus tard le 31/12 de l'exercice a une dotation arrêtée à la date de
     * sortie, mais il n'est plus au patrimoine à la clôture : ni cumul, ni VNC, ni « totalement amorti ».
     */
    public static function heldAtYearEnd(mixed $disposedAt, int $year): bool
    {
        if ($disposedAt === null || $disposedAt === '') {
            return true;
        }

        return substr((string) ($disposedAt instanceof \DateTimeInterface ? $disposedAt->format('Y-m-d') : $disposedAt), 0, 10) > $year.'-12-31';
    }

    /**
     * @param  Builder<Asset>  $query
     * @return Builder<Asset>
     */
    public function applyTab(Builder $query, string $tab, int $year): Builder
    {
        $lines = fn (string $operator) => fn (QueryBuilder $sub) => $sub->from('depreciation_lines')
            ->whereColumn('depreciation_lines.depreciation_schedule_id', 'ds.id')
            ->where('depreciation_lines.fiscal_year', $operator, $year);

        return match ($tab) {
            self::TAB_ENDING => $query->whereNotNull('ds.id')->whereExists($lines('='))->whereNotExists($lines('>')),
            self::TAB_FULLY => $query->whereNotNull('ds.id')->whereExists($lines('<='))->whereNotExists($lines('>'))
                ->where(fn (Builder $held) => $held->whereNull('assets.disposed_at')->orWhere('assets.disposed_at', '>', $year.'-12-31')),
            self::TAB_NONE => $query->whereNull('ds.id'),
            default => $query->whereNotNull('ds.id')->whereExists($lines('<='))->whereExists($lines('>')),
        };
    }

    /**
     * Compteurs et récapitulatif par catégorie de l'exercice.
     *
     * @param  Builder<Asset>  $query
     * @return array{
     *     totals: array{count: int, original: int, amount: int, accumulated: int, net: int, fully: int, ending: int, current: int, none: int},
     *     categories: list<array{name: string, duration: int|null, count: int, original: int, amount: int, accumulated: int, net: int}>,
     *     sites: array<int, int>,
     * }
     */
    public function summary(Builder $query, int $year): array
    {
        $totals = ['count' => 0, 'original' => 0, 'amount' => 0, 'accumulated' => 0, 'net' => 0, 'fully' => 0, 'ending' => 0, 'current' => 0, 'none' => 0];
        $categories = [];
        $sites = [];
        $names = AssetCategory::query()->get()->keyBy('id');

        foreach ($query->toBase()->cursor() as $row) {
            $row = (array) $row;

            if ($row['schedule_id'] === null) {
                $totals['none']++;

                continue;
            }

            $accumulated = $row['accumulated_to_year'] === null ? null : (int) $row['accumulated_to_year'];

            if ($accumulated === null) {
                continue;
            }

            $original = (int) $row['schedule_original_value'];
            $amount = (int) ($row['year_amount'] ?? 0);
            $lastYear = (int) $row['last_year'];

            if (! self::heldAtYearEnd($row['disposed_at'] ?? null, $year)) {
                // Sorti dans l'exercice : seule la dotation jusqu'à la sortie compte.
                $totals['amount'] += $amount;
                $id = (int) $row['asset_category_id'];
                $category = $names->get($id);
                $categories[$id] ??= ['name' => $category->name ?? '—', 'duration' => $category?->default_duration_years, 'count' => 0, 'original' => 0, 'amount' => 0, 'accumulated' => 0, 'net' => 0];
                $categories[$id]['amount'] += $amount;

                continue;
            }

            $site = (int) $row['site_id'];
            $sites[$site] = ($sites[$site] ?? 0) + $original - $accumulated;

            $totals['count']++;
            $totals['original'] += $original;
            $totals['amount'] += $amount;
            $totals['accumulated'] += $accumulated;
            $totals['net'] += $original - $accumulated;
            $totals['fully'] += $lastYear <= $year ? 1 : 0;
            $totals['ending'] += $lastYear === $year ? 1 : 0;
            $totals['current'] += $lastYear > $year ? 1 : 0;

            $id = (int) $row['asset_category_id'];
            $category = $names->get($id);
            $categories[$id] ??= ['name' => $category->name ?? '—', 'duration' => $category?->default_duration_years, 'count' => 0, 'original' => 0, 'amount' => 0, 'accumulated' => 0, 'net' => 0];
            $categories[$id]['count']++;
            $categories[$id]['original'] += $original;
            $categories[$id]['amount'] += $amount;
            $categories[$id]['accumulated'] += $accumulated;
            $categories[$id]['net'] += $original - $accumulated;
        }

        usort($categories, fn (array $a, array $b): int => strcmp($a['name'], $b['name']));

        return ['totals' => $totals, 'categories' => $categories, 'sites' => $sites];
    }
}
