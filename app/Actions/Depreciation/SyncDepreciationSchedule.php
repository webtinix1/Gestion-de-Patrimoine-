<?php

namespace App\Actions\Depreciation;

use App\Enums\DepreciationMethod;
use App\Models\Asset;
use App\Models\DepreciationLine;
use App\Models\DepreciationSchedule;
use App\Services\Depreciation\DepreciationInput;
use App\Services\Depreciation\DepreciationLineResult;
use App\Services\Depreciation\DepreciationPlan;
use App\Services\Depreciation\FixedLine;
use App\Services\Depreciation\ImpossibleDepreciationPlan;
use App\Services\Depreciation\InvalidDepreciationInput;
use App\Services\DepreciationCalculator;
use App\Services\DepreciationRules;
use App\Services\FiscalYearCalendar;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * Génère ou recalcule le plan courant d'un bien amortissable.
 *
 * - RG-36 : les lignes figées (exercice clôturé ou antérieur au premier exercice ouvert) sont reprises
 *   telles quelles ; seules les lignes des exercices ouverts ou à venir sont recalculées.
 * - RG-38 : une version validée est conservée ; tout changement crée alors une nouvelle version.
 * - Idempotent : relancé sans changement de paramètre, il ne modifie rien.
 */
class SyncDepreciationSchedule
{
    /**
     * Paramètres de la fiche qui déterminent le plan (RG-37).
     *
     * @var list<string>
     */
    public const array TRACKED_ATTRIBUTES = [
        'acquisition_cost', 'residual_value', 'duration_years', 'in_service_on', 'depreciation_method',
        'declining_coefficient', 'is_depreciable',
    ];

    public function __construct(
        private DepreciationCalculator $calculator,
        private DepreciationRules $rules,
    ) {}

    /**
     * @param  int|null  $anchorYear  RG-37 : premier exercice ouvert lors d'un changement de paramètre
     */
    public function __invoke(Asset $asset, ?int $anchorYear = null, ?FiscalYearCalendar $calendar = null): SyncOutcome
    {
        $calendar ??= new FiscalYearCalendar;

        return DB::transaction(function () use ($asset, $anchorYear, $calendar): SyncOutcome {
            $asset = Asset::query()->withoutGlobalScopes()->lockForUpdate()->findOrFail($asset->getKey());
            $current = $this->currentSchedule($asset);

            if (! self::isEligible($asset)) {
                if ($current === null) {
                    return SyncOutcome::Skipped;
                }

                if ($current->isEngaged()) {
                    return SyncOutcome::Blocked;
                }

                // Plan jamais engagé d'un bien devenu non amortissable : conservé, mais plus courant.
                $current->update(['is_current' => false]);

                return SyncOutcome::Retired;
            }

            $this->fixCoefficient($asset);

            $firstOpen = $calendar->firstRecalculableYear();
            /** @var Collection<int, DepreciationLine> $existing */
            $existing = $current === null ? collect() : $current->lines->keyBy('fiscal_year');
            $anchor = $anchorYear ?? $current?->recalculated_from_year;
            $plan = $this->calculate($asset, $this->fixedLines($existing, $firstOpen), $anchor);
            $header = $this->header($asset, $plan, $anchor);
            $lines = $this->targetLines($plan, $existing, $firstOpen);

            if ($current !== null && $this->isSame($current, $header, $lines)) {
                return SyncOutcome::Unchanged;
            }

            if ($current === null || $current->isValidated()) {
                $this->createVersion($asset, $current, $header, $lines);

                return $current === null ? SyncOutcome::Created : SyncOutcome::Updated;
            }

            $this->updateInPlace($current, $header, $lines);

            return SyncOutcome::Updated;
        });
    }

    /**
     * Après une modification de la fiche (E07). Un plan engagé ne se modifie que depuis son écran,
     * avec un motif (RG-37) ; un plan jamais engagé est simplement recalculé.
     */
    public function afterAssetUpdate(Asset $asset): void
    {
        $changed = array_values(array_intersect(self::TRACKED_ATTRIBUTES, array_keys($asset->getChanges())));

        if ($changed === []) {
            return;
        }

        if ($this->currentSchedule($asset)?->isEngaged()) {
            $field = match ($changed[0]) {
                'is_depreciable' => 'depreciation_forced_off',
                'depreciation_method', 'declining_coefficient' => 'duration_years',
                default => $changed[0],
            };

            throw ValidationException::withMessages([
                'data.'.$field => 'Ce bien a un plan d\'amortissement engagé (exercice clôturé ou plan validé). '
                    .'Sa valeur d\'origine, sa durée, sa valeur résiduelle et sa date de mise en service se modifient '
                    .'depuis son plan d\'amortissement, action « Modifier les paramètres », avec un motif (RG-37).',
            ]);
        }

        $this($asset);
    }

    /**
     * RG-42 : vérifie, sans rien écrire, que le plan du bien se recalcule. Renvoie le problème en
     * clair, ou null.
     */
    public function anomaly(Asset $asset, FiscalYearCalendar $calendar): ?string
    {
        $current = $this->currentSchedule($asset);

        if (! self::isEligible($asset)) {
            return $current !== null && $current->isEngaged()
                ? 'plan engagé conservé alors que le bien n\'est plus amortissable'
                : null;
        }

        try {
            $this->calculate($asset, $this->fixedLines($current === null ? collect() : $current->lines->keyBy('fiscal_year'), $calendar->firstRecalculableYear()), $current?->recalculated_from_year);
        } catch (ValidationException $exception) {
            return collect($exception->errors())->flatten()->implode(' ');
        }

        return null;
    }

    /**
     * RG-39 : plan que donnerait une sortie à cette date, calculé sans rien écrire (écran de sortie E26).
     * Null pour un bien sans plan possible.
     */
    public function previewDisposal(Asset $asset, CarbonImmutable $disposedOn, FiscalYearCalendar $calendar): ?DepreciationPlan
    {
        if (! self::isEligible($asset)) {
            return null;
        }

        $current = $this->currentSchedule($asset);
        $simulated = (clone $asset)->setAttribute('disposed_at', $disposedOn->toDateString());

        return $this->calculate(
            $simulated,
            $this->fixedLines($current === null ? collect() : $current->lines->keyBy('fiscal_year'), $calendar->firstRecalculableYear()),
            $current?->recalculated_from_year,
        );
    }

    /**
     * RG-36 : lignes figées (exercice clôturé ou antérieur au premier exercice recalculable).
     *
     * @param  Collection<int, DepreciationLine>  $existing
     * @return array<int, FixedLine>
     */
    private function fixedLines(Collection $existing, ?int $firstOpen): array
    {
        $fixed = [];

        foreach ($existing as $year => $line) {
            if ($line->is_locked || ($firstOpen !== null && $year < $firstOpen)) {
                $fixed[$year] = new FixedLine($year, $line->amount, days: $line->days, months: $line->months);
            }
        }

        return $fixed;
    }

    /**
     * RG-35 : le coefficient dégressif est écrit sur la fiche au premier calcul ; un changement
     * ultérieur des paramètres généraux ne concerne que les nouveaux biens.
     */
    private function fixCoefficient(Asset $asset): void
    {
        if ($asset->depreciation_method !== DepreciationMethod::Declining || $asset->getAttribute('declining_coefficient') !== null) {
            return;
        }

        $coefficient = $this->rules->coefficientFor((int) $asset->duration_years);

        if ($coefficient === null) {
            return;
        }

        // Écriture directe : ni nouvelle version de la fiche, ni entrée de journal pour une valeur dérivée.
        Asset::query()->withoutGlobalScopes()->whereKey($asset->getKey())->update(['declining_coefficient' => $coefficient]);
        $asset->setAttribute('declining_coefficient', $coefficient);
        $asset->syncOriginalAttribute('declining_coefficient');
    }

    public static function isEligible(Asset $asset): bool
    {
        return $asset->is_depreciable
            && (int) $asset->acquisition_cost > 0
            && (int) $asset->duration_years > 0
            && $asset->in_service_on !== null;
    }

    public function currentSchedule(Asset $asset): ?DepreciationSchedule
    {
        return DepreciationSchedule::query()
            ->where('asset_id', $asset->getKey())
            ->where('is_current', true)
            ->with('lines')
            ->first();
    }

    /**
     * @param  array<int, FixedLine>  $fixed
     */
    public function input(Asset $asset, array $fixed = [], ?int $anchor = null): DepreciationInput
    {
        $method = $asset->depreciation_method ?? DepreciationMethod::Linear;
        $duration = (int) $asset->duration_years;
        $coefficient = null;

        if ($method === DepreciationMethod::Declining) {
            $stored = $asset->getAttribute('declining_coefficient');
            $coefficient = is_scalar($stored) ? (string) $stored : $this->rules->coefficientFor($duration);

            if ($coefficient === null) {
                throw ValidationException::withMessages([
                    'data.duration_years' => 'La méthode dégressive s\'applique à une durée d\'au moins 3 ans (RG-35).',
                ]);
            }
        }

        return new DepreciationInput(
            method: $method,
            originalValue: (int) $asset->acquisition_cost,
            residualValue: $asset->residual_value,
            durationYears: $duration,
            inServiceOn: CarbonImmutable::parse((string) $asset->in_service_on?->toDateString()),
            decliningCoefficient: $coefficient === null ? null : (string) $coefficient,
            disposedOn: $asset->disposed_at === null ? null : CarbonImmutable::parse($asset->disposed_at->toDateString()),
            fixedLines: $fixed,
            recalculatedFromYear: $anchor,
        );
    }

    /**
     * @param  array<int, FixedLine>  $fixed
     */
    private function calculate(Asset $asset, array $fixed, ?int $anchor): DepreciationPlan
    {
        if ($asset->residual_value < 0 || $asset->residual_value >= (int) $asset->acquisition_cost) {
            throw ValidationException::withMessages([
                'data.residual_value' => 'La valeur résiduelle doit être positive et inférieure au prix d\'acquisition.',
            ]);
        }

        try {
            return $this->calculator->calculate($this->input($asset, $fixed, $anchor));
        } catch (ImpossibleDepreciationPlan|InvalidDepreciationInput $exception) {
            // Message métier rattaché au paramètre en cause.
            throw ValidationException::withMessages(['data.'.$exception->field => $exception->getMessage()]);
        } catch (InvalidArgumentException) {
            throw ValidationException::withMessages([
                'data.duration_years' => 'Les paramètres d\'amortissement de ce bien sont incohérents : vérifiez la durée, le coefficient et les valeurs.',
            ]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function header(Asset $asset, DepreciationPlan $plan, ?int $anchor): array
    {
        $method = $asset->depreciation_method ?? DepreciationMethod::Linear;
        $coefficient = $method === DepreciationMethod::Declining
            ? DepreciationCalculator::formatScaled(DepreciationCalculator::parseScaled((string) $this->input($asset)->decliningCoefficient))
            : null;

        return [
            'method' => $method->value,
            'original_value' => (int) $asset->acquisition_cost,
            'base_amount' => $plan->base,
            'duration_years' => (int) $asset->duration_years,
            'rate' => $plan->rate,
            'declining_coefficient' => $coefficient,
            'residual_value' => $asset->residual_value,
            'starts_on' => $plan->startsOn->toDateString(),
            'ends_on' => $plan->endsOn->toDateString(),
            'recalculated_from_year' => $anchor,
        ];
    }

    /**
     * Lignes visées. Une ligne figée garde exactement ses valeurs enregistrées.
     *
     * @param  Collection<int, DepreciationLine>  $existing
     * @return array<int, array<string, mixed>>
     */
    private function targetLines(DepreciationPlan $plan, Collection $existing, ?int $firstOpen): array
    {
        $lines = [];

        foreach ($plan->lines as $line) {
            $stored = $existing->get($line->year);

            $lines[$line->year] = $line->fixed && $stored instanceof DepreciationLine
                ? [...$this->values($stored), 'is_locked' => true]
                : [...$this->resultValues($line), 'is_locked' => $firstOpen !== null && $line->year < $firstOpen];
        }

        return $lines;
    }

    /**
     * @return array<string, mixed>
     */
    private function resultValues(DepreciationLineResult $line): array
    {
        return [
            'fiscal_year' => $line->year,
            'days' => $line->days,
            'months' => $line->months,
            'base' => $line->base,
            'rate' => $line->rate,
            'amount' => $line->amount,
            'accumulated' => $line->accumulated,
            'net_book_value' => $line->netBookValue,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function values(DepreciationLine $line): array
    {
        return [
            'fiscal_year' => $line->fiscal_year,
            'days' => $line->days,
            'months' => $line->months,
            'base' => $line->base,
            'rate' => $line->rate === null ? null : (string) $line->rate,
            'amount' => $line->amount,
            'accumulated' => $line->accumulated,
            'net_book_value' => $line->net_book_value,
        ];
    }

    /**
     * @param  array<string, mixed>  $header
     * @param  array<int, array<string, mixed>>  $lines
     */
    private function isSame(DepreciationSchedule $schedule, array $header, array $lines): bool
    {
        $stored = [
            'method' => $schedule->method->value,
            'original_value' => $schedule->original_value,
            'base_amount' => $schedule->base_amount,
            'duration_years' => $schedule->duration_years,
            'rate' => (string) $schedule->rate,
            'declining_coefficient' => $schedule->declining_coefficient === null ? null : (string) $schedule->declining_coefficient,
            'residual_value' => $schedule->residual_value,
            'starts_on' => $schedule->starts_on->toDateString(),
            'ends_on' => $schedule->ends_on->toDateString(),
            'recalculated_from_year' => $schedule->recalculated_from_year,
        ];

        if ($stored !== $header) {
            return false;
        }

        $current = $schedule->lines->mapWithKeys(fn (DepreciationLine $line): array => [
            $line->fiscal_year => [...$this->values($line), 'is_locked' => $line->is_locked],
        ])->all();

        ksort($current);
        ksort($lines);

        return $current === $lines;
    }

    /**
     * @param  array<string, mixed>  $header
     * @param  array<int, array<string, mixed>>  $lines
     */
    private function createVersion(Asset $asset, ?DepreciationSchedule $previous, array $header, array $lines): void
    {
        $previous?->update(['is_current' => false]);

        $version = (int) DepreciationSchedule::query()->where('asset_id', $asset->getKey())->max('version') + 1;
        $schedule = DepreciationSchedule::query()->create([
            ...$header,
            'asset_id' => $asset->getKey(),
            'version' => $version,
            'is_current' => true,
        ]);

        foreach ($lines as $values) {
            $schedule->lines()->create($values);
        }
    }

    /**
     * Plan courant non validé : seules ses lignes non figées bougent (RG-36).
     *
     * @param  array<string, mixed>  $header
     * @param  array<int, array<string, mixed>>  $lines
     */
    private function updateInPlace(DepreciationSchedule $schedule, array $header, array $lines): void
    {
        $schedule->update($header);
        $existing = $schedule->lines->keyBy('fiscal_year');

        foreach ($lines as $year => $values) {
            $line = $existing->get($year);

            if ($line === null) {
                $schedule->lines()->create($values);
            } elseif (! $line->is_locked) {
                $line->fill($values);

                if ($line->isDirty()) {
                    $line->save();
                }
            }
        }

        foreach ($existing as $year => $line) {
            if (! array_key_exists($year, $lines) && ! $line->is_locked) {
                $line->delete();
            }
        }
    }
}
