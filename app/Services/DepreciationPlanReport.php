<?php

namespace App\Services;

use App\Enums\DepreciationMethod;
use App\Models\Asset;
use App\Models\DepreciationLine;
use App\Models\DepreciationParameterChange;
use App\Models\DepreciationSchedule;
use App\Models\User;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;

/**
 * Données d'affichage d'un plan d'amortissement (E31, exports PDF et Excel, onglet de la fiche).
 */
class DepreciationPlanReport
{
    public function current(Asset $asset): ?DepreciationSchedule
    {
        return DepreciationSchedule::query()
            ->where('asset_id', $asset->getKey())
            ->where('is_current', true)
            ->with(['lines', 'validator'])
            ->first();
    }

    /**
     * @return array{
     *     schedule: DepreciationSchedule|null,
     *     lines: list<array<string, mixed>>,
     *     parameters: list<array{label: string, value: string}>,
     *     totals: array{units: int, amount: int},
     *     unit: string,
     *     currentYear: int|null,
     *     chart: list<array{label: string, value: int, percent: int}>,
     *     counters: array<string, string>,
     *     history: list<array<string, string>>,
     *     versions: list<array<string, string>>,
     * }
     */
    public function build(Asset $asset, ?CarbonImmutable $today = null): array
    {
        $today ??= CarbonImmutable::today();
        $calendar = new FiscalYearCalendar;
        $schedule = $this->current($asset);
        $declining = $schedule?->method === DepreciationMethod::Declining;
        $currentYear = $calendar->current()->year ?? $today->year;

        $lines = $schedule === null ? [] : $schedule->lines->map(function (DepreciationLine $line) use ($calendar, $schedule): array {
            $status = $calendar->lineStatus($line->fiscal_year);

            return [
                'year' => $line->fiscal_year,
                'units' => $line->months ?? $line->days,
                'original_value' => $schedule->original_value,
                'base' => $line->base,
                'rate' => self::percent((string) $line->rate),
                'amount' => $line->amount,
                'accumulated' => $line->accumulated,
                'net_book_value' => $line->net_book_value,
                'status' => $status->getLabel(),
                'status_color' => $status->getColor(),
                'is_locked' => $line->is_locked,
            ];
        })->values()->all();

        return [
            'schedule' => $schedule,
            'lines' => $lines,
            'parameters' => $schedule === null ? [] : $this->parameters($asset, $schedule, $currentYear),
            'totals' => [
                'units' => (int) array_sum(array_column($lines, 'units')),
                'amount' => (int) array_sum(array_column($lines, 'amount')),
            ],
            'unit' => $declining ? 'Mois' : 'Jours',
            'currentYear' => $schedule === null ? null : $currentYear,
            'chart' => $schedule === null ? [] : $this->chart($schedule),
            'counters' => $this->counters($asset, $schedule, $currentYear, $today),
            'history' => $this->history($asset),
            'versions' => $this->versions($asset),
        ];
    }

    /**
     * Compteurs de la fiche (E06) : valeur d'origine, cumul et VNC à la fin de l'exercice en cours, durée restante.
     *
     * @return array<string, string>
     */
    public function counters(Asset $asset, ?DepreciationSchedule $schedule, int $year, CarbonImmutable $today): array
    {
        if ($schedule === null) {
            return [];
        }

        $line = $schedule->lines->filter(fn (DepreciationLine $line): bool => $line->fiscal_year <= $year)->last();
        $accumulated = $line instanceof DepreciationLine ? $line->accumulated : 0;

        return [
            'Valeur d\'origine' => Money::format($schedule->original_value),
            'Amort. cumulés au 31/12/'.$year => Money::format($accumulated),
            'VNC au 31/12/'.$year => Money::format($schedule->original_value - $accumulated),
            'Durée restante' => self::remaining($schedule, $asset, $today),
        ];
    }

    public static function remaining(DepreciationSchedule $schedule, Asset $asset, CarbonImmutable $today): string
    {
        $end = CarbonImmutable::parse($schedule->ends_on->toDateString());

        if ($asset->disposed_at !== null) {
            return 'Sorti du patrimoine';
        }

        if ($today->gte($end)) {
            return 'Totalement amorti';
        }

        $start = $today->max(CarbonImmutable::parse($schedule->starts_on->toDateString()));
        $diff = $start->diff($end->addDay());
        $parts = array_filter([
            $diff->y > 0 ? $diff->y.' an'.($diff->y > 1 ? 's' : '') : null,
            $diff->m > 0 ? $diff->m.' mois' : null,
            $diff->d > 0 ? $diff->d.' j' : null,
        ]);

        return $parts === [] ? '—' : implode(' ', $parts);
    }

    public static function percent(string $rate): string
    {
        $hundredths = DepreciationCalculator::roundHalfUp(DepreciationCalculator::parseScaled($rate), 100);

        return intdiv($hundredths, 100).','.str_pad((string) ($hundredths % 100), 2, '0', STR_PAD_LEFT)."\u{00A0}%";
    }

    /**
     * @return list<array{label: string, value: string}>
     */
    private function parameters(Asset $asset, DepreciationSchedule $schedule, int $year): array
    {
        $declining = $schedule->method === DepreciationMethod::Declining;
        $annual = DepreciationCalculator::roundHalfUp($schedule->base_amount, max(1, $schedule->duration_years));
        $current = $schedule->lines->firstWhere('fiscal_year', $year);

        return array_values(array_filter([
            ['label' => 'Valeur d\'origine', 'value' => Money::format($schedule->original_value, withCurrency: true)],
            ['label' => 'Valeur résiduelle', 'value' => Money::format($schedule->residual_value, withCurrency: true)],
            ['label' => 'Base amortissable', 'value' => Money::format($schedule->base_amount, withCurrency: true)],
            ['label' => 'Date d\'acquisition', 'value' => $asset->acquired_on->format('d/m/Y')],
            ['label' => 'Mise en service', 'value' => $schedule->starts_on->format('d/m/Y')],
            ['label' => 'Méthode', 'value' => $schedule->method->getLabel().($declining ? ' (coefficient '.str_replace('.', ',', rtrim(rtrim((string) $schedule->declining_coefficient, '0'), '.')).')' : '')],
            ['label' => 'Durée · taux', 'value' => $schedule->duration_years.' ans · '.self::percent((string) $schedule->rate)],
            $declining ? null : ['label' => 'Dotation annuelle théorique', 'value' => Money::format($annual, withCurrency: true)],
            ['label' => 'Prorata', 'value' => $declining ? 'En mois, à partir du mois de mise en service' : 'Temporis, jours, base 360'],
            ['label' => 'Comptes', 'value' => implode(' · ', array_filter([$asset->asset_account, $asset->depreciation_account, $asset->category?->expense_account])) ?: '—'],
            ['label' => 'Fin d\'amortissement', 'value' => $schedule->ends_on->format('d/m/Y')],
            $current instanceof DepreciationLine && $schedule->recalculated_from_year !== null
                ? ['label' => 'Recalculé à partir de', 'value' => 'Exercice '.$schedule->recalculated_from_year]
                : null,
        ]));
    }

    /**
     * Évolution de la VNC (E31) : barres proportionnelles, sans bibliothèque externe.
     *
     * @return list<array{label: string, value: int, percent: int}>
     */
    private function chart(DepreciationSchedule $schedule): array
    {
        $max = max(1, $schedule->original_value);
        $points = [['label' => 'Origine', 'value' => $schedule->original_value, 'percent' => 100]];

        foreach ($schedule->lines as $line) {
            $points[] = [
                'label' => (string) $line->fiscal_year,
                'value' => $line->net_book_value,
                'percent' => intdiv($line->net_book_value * 100, $max),
            ];
        }

        return $points;
    }

    /**
     * Historique des modifications (RG-37), des validations et de la création du plan.
     *
     * @return list<array<string, string>>
     */
    private function history(Asset $asset): array
    {
        $entries = [];

        $changes = DepreciationParameterChange::query()->where('asset_id', $asset->getKey())->with('changer')->get();

        foreach ($changes as $change) {
            $entries[] = [
                'sort' => $change->changed_at->format('YmdHis').'-2-'.$change->id,
                'date' => $change->changed_at->format('d/m/Y'),
                'parameter' => $change->parameterLabel(),
                'old' => self::displayValue($change->parameter, $change->old_value),
                'new' => self::displayValue($change->parameter, $change->new_value),
                'by' => self::userName($change->changer),
                'reason' => $change->reason.($change->effective_year !== null ? ' (à partir de '.$change->effective_year.')' : ''),
            ];
        }

        $schedules = DepreciationSchedule::query()->where('asset_id', $asset->getKey())->with(['validator', 'creator'])->orderBy('version')->get();

        foreach ($schedules as $schedule) {
            if ($schedule->version === 1) {
                $entries[] = [
                    'sort' => $schedule->created_at?->format('YmdHis').'-1-'.$schedule->id,
                    'date' => $schedule->created_at?->format('d/m/Y') ?? '—',
                    'parameter' => 'Création du plan',
                    'old' => '—',
                    'new' => $schedule->duration_years.' ans, '.mb_strtolower($schedule->method->getLabel()),
                    'by' => self::userName($schedule->creator),
                    'reason' => 'Règle de la catégorie',
                ];
            }

            if ($schedule->validated_at instanceof Carbon) {
                $entries[] = [
                    'sort' => $schedule->validated_at->format('YmdHis').'-3-'.$schedule->id,
                    'date' => $schedule->validated_at->format('d/m/Y'),
                    'parameter' => 'Validation du plan',
                    'old' => '—',
                    'new' => 'Version '.$schedule->version,
                    'by' => self::userName($schedule->validator),
                    'reason' => 'RG-38',
                ];
            }
        }

        usort($entries, fn (array $a, array $b): int => strcmp($b['sort'], $a['sort']));

        return array_map(function (array $entry): array {
            unset($entry['sort']);

            return $entry;
        }, $entries);
    }

    /**
     * @return list<array<string, string>>
     */
    private function versions(Asset $asset): array
    {
        return DepreciationSchedule::query()
            ->where('asset_id', $asset->getKey())
            ->with('validator')
            ->orderByDesc('version')
            ->get()
            ->map(fn (DepreciationSchedule $schedule): array => [
                'version' => 'Version '.$schedule->version,
                'state' => $schedule->is_current ? 'Courante' : 'Archivée',
                'validated' => $schedule->validated_at === null ? 'Non validée' : 'Validée le '.$schedule->validated_at->format('d/m/Y').' par '.self::userName($schedule->validator),
                'parameters' => $schedule->duration_years.' ans · base '.Money::format($schedule->base_amount).' · fin '.$schedule->ends_on->format('d/m/Y'),
            ])
            ->values()
            ->all();
    }

    public static function displayValue(string $parameter, ?string $value): string
    {
        if ($value === null || $value === '') {
            return '—';
        }

        return match ($parameter) {
            'acquisition_cost', 'residual_value' => Money::format((int) $value, withCurrency: true),
            'duration_years' => $value.' ans',
            'in_service_on' => CarbonImmutable::parse($value)->format('d/m/Y'),
            'depreciation_method' => DepreciationMethod::tryFrom($value)?->getLabel() ?? $value,
            default => $value,
        };
    }

    private static function userName(?User $user): string
    {
        return $user?->fullName() ?? 'Système';
    }
}
