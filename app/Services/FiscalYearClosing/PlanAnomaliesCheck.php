<?php

namespace App\Services\FiscalYearClosing;

use App\Actions\Depreciation\SyncDepreciationSchedule;
use App\Models\Asset;
use App\Models\DepreciationSchedule;
use App\Models\FiscalYear;
use App\Services\FiscalYearCalendar;

/**
 * RG-42 : plans qui ne pourront pas être recalculés au moment de la clôture, vérifiés sans rien
 * écrire. Affichés avant la clôture, à confirmer.
 */
class PlanAnomaliesCheck implements AttentionPointProvider
{
    private const int SHOWN = 10;

    public function __construct(private SyncDepreciationSchedule $sync) {}

    public function points(FiscalYear $fiscalYear): array
    {
        $calendar = new FiscalYearCalendar;
        $anomalies = [];

        Asset::query()->withoutGlobalScopes()
            ->where(fn ($query) => $query->where('is_depreciable', true)
                ->orWhereIn('id', DepreciationSchedule::query()->select('asset_id')->where('is_current', true)))
            ->orderBy('id')
            ->chunkById(200, function ($assets) use ($calendar, &$anomalies): void {
                foreach ($assets as $asset) {
                    $problem = $this->sync->anomaly($asset, $calendar);

                    if ($problem !== null) {
                        $anomalies[] = $asset->number.' : '.$problem;
                    }
                }
            });

        if ($anomalies === []) {
            return [];
        }

        $count = count($anomalies);
        $label = $count.' plan'.($count > 1 ? 's' : '').' ne pourr'.($count > 1 ? 'ont' : 'a').' pas être recalculé'.($count > 1 ? 's' : '').' et rester'.($count > 1 ? 'ont' : 'a').' en l\'état : '
            .implode(' ; ', array_slice($anomalies, 0, self::SHOWN))
            .($count > self::SHOWN ? ' ; et '.($count - self::SHOWN).' autre(s)' : '');

        return [new AttentionPoint('plan_anomalies', $label, AttentionPoint::WARNING)];
    }
}
