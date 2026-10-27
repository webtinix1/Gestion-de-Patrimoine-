<?php

namespace App\Services\FiscalYearClosing;

use App\Models\Asset;
use App\Models\DepreciationSchedule;
use App\Models\FiscalYear;

/**
 * Plans de l'exercice : dotations calculées, biens amortissables sans plan, plans à valider.
 */
class DepreciationPlansCheck implements AttentionPointProvider
{
    public function points(FiscalYear $fiscalYear): array
    {
        $withLine = DepreciationSchedule::query()
            ->where('is_current', true)
            ->whereHas('lines', fn ($query) => $query->where('fiscal_year', $fiscalYear->year));

        $planned = (clone $withLine)->count();
        $unvalidated = (clone $withLine)->whereNull('validated_at')->count();

        $missing = Asset::query()->withoutGlobalScopes()
            ->where('is_depreciable', true)
            ->whereNull('disposed_at')
            ->whereNotIn('id', DepreciationSchedule::query()->select('asset_id')->where('is_current', true))
            ->count();

        $points = [new AttentionPoint('plans', 'Dotations '.$fiscalYear->year.' calculées pour '.$planned.' immobilisation'.($planned > 1 ? 's' : ''), AttentionPoint::OK)];

        if ($missing > 0) {
            $points[] = new AttentionPoint('missing_plans', $missing.' bien'.($missing > 1 ? 's' : '').' amortissable'.($missing > 1 ? 's' : '').' sans plan (date de mise en service ou durée manquante)', AttentionPoint::WARNING);
        }

        if ($unvalidated > 0) {
            $points[] = new AttentionPoint('unvalidated_plans', $unvalidated.' plan'.($unvalidated > 1 ? 's' : '').' non encore validé'.($unvalidated > 1 ? 's' : '').' : la clôture les validera (RG-38)', AttentionPoint::OK);
        }

        return $points;
    }
}
