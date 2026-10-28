<?php

namespace App\Services;

use App\Actions\Depreciation\SyncDepreciationSchedule;
use App\Models\Asset;
use App\Models\DepreciationLine;
use App\Models\DepreciationSchedule;
use App\Services\Depreciation\DepreciationLineResult;
use Carbon\CarbonImmutable;

/**
 * RG-39 : valeur d'origine, amortissements cumulés et VNC d'un bien à sa date de sortie.
 * Le panneau de l'écran E26 les calcule en direct (sans rien écrire) ; la sortie les fige à partir
 * du plan arrêté qu'elle enregistre. Les deux passent par le même calcul (DepreciationCalculator).
 */
class DisposalValuation
{
    public function __construct(private SyncDepreciationSchedule $sync) {}

    /**
     * Valeurs qu'aurait une sortie à cette date. Peut lever une ValidationException si le plan du
     * bien ne peut pas être recalculé.
     */
    public function preview(Asset $asset, CarbonImmutable $disposedOn, ?FiscalYearCalendar $calendar = null): DisposalValues
    {
        $plan = $this->sync->previewDisposal($asset, $disposedOn, $calendar ?? new FiscalYearCalendar);

        if ($plan === null) {
            return DisposalValues::withoutPlan((int) $asset->acquisition_cost);
        }

        $lines = array_map(fn (DepreciationLineResult $line): array => [$line->year, $line->amount, $line->accumulated], $plan->lines);

        return DisposalValues::fromLines($this->originalValue($asset), $lines, $disposedOn->year);
    }

    /**
     * Valeurs lues dans le plan arrêté enregistré par la sortie.
     */
    public function fromSchedule(Asset $asset, ?DepreciationSchedule $schedule, CarbonImmutable $disposedOn): DisposalValues
    {
        if ($schedule === null) {
            return DisposalValues::withoutPlan((int) $asset->acquisition_cost);
        }

        $lines = $schedule->lines
            ->sortBy('fiscal_year')
            ->map(fn (DepreciationLine $line): array => [$line->fiscal_year, $line->amount, $line->accumulated])
            ->values()
            ->all();

        return DisposalValues::fromLines($schedule->original_value, $lines, $disposedOn->year);
    }

    private function originalValue(Asset $asset): int
    {
        return (int) $asset->acquisition_cost;
    }
}
