<?php

namespace App\Actions\Depreciation;

use App\Models\Asset;
use App\Models\DepreciationSchedule;
use App\Services\FiscalYearCalendar;
use Illuminate\Validation\ValidationException;

/**
 * Recalcule les plans de tous les biens (exercices ouverts uniquement, RG-36). Idempotent :
 * relancé sans changement, il ne modifie rien. Utilisé par la commande patrimoine:recalculer-plans
 * et par la clôture d'un exercice.
 */
class RecalculateDepreciationSchedules
{
    public function __construct(private SyncDepreciationSchedule $sync) {}

    /**
     * @return array{counts: array<string, int>, anomalies: list<string>}
     */
    public function __invoke(): array
    {
        $calendar = new FiscalYearCalendar;
        $counts = array_fill_keys(array_map(fn (SyncOutcome $outcome): string => $outcome->value, SyncOutcome::cases()), 0);
        $anomalies = [];

        $withPlan = DepreciationSchedule::query()->where('is_current', true)->select('asset_id');

        Asset::query()->withoutGlobalScopes()
            ->where(fn ($query) => $query->where('is_depreciable', true)->orWhereIn('id', $withPlan))
            ->orderBy('id')
            ->chunkById(200, function ($assets) use ($calendar, &$counts, &$anomalies): void {
                foreach ($assets as $asset) {
                    try {
                        $outcome = ($this->sync)($asset, calendar: $calendar);
                    } catch (ValidationException $exception) {
                        $anomalies[] = $asset->number.' : '.collect($exception->errors())->flatten()->implode(' ');

                        continue;
                    }

                    $counts[$outcome->value]++;

                    if ($outcome === SyncOutcome::Blocked) {
                        $anomalies[] = $asset->number.' : plan engagé conservé alors que le bien n\'est plus amortissable.';
                    }
                }
            });

        return ['counts' => $counts, 'anomalies' => $anomalies];
    }
}
