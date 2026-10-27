<?php

namespace App\Console\Commands;

use App\Actions\Depreciation\RecalculateDepreciationSchedules;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Recalcule les plans d'amortissement sur les exercices ouverts uniquement (RG-36). Idempotent.
 */
#[Signature('patrimoine:recalculer-plans')]
#[Description('Recalcule les plans d\'amortissement des exercices ouverts (les exercices clôturés ne bougent pas)')]
class RecalculateDepreciationPlans extends Command
{
    public function handle(RecalculateDepreciationSchedules $recalculate): int
    {
        $report = $recalculate();
        $counts = $report['counts'];
        $changed = $counts['created'] + $counts['updated'] + $counts['retired'];

        $this->table(['Résultat', 'Biens'], [
            ['Plans créés', $counts['created']],
            ['Plans recalculés', $counts['updated']],
            ['Plans inchangés', $counts['unchanged']],
            ['Plans retirés (bien devenu non amortissable)', $counts['retired']],
            ['Biens sans plan (données incomplètes)', $counts['skipped']],
        ]);

        foreach ($report['anomalies'] as $anomaly) {
            $this->warn($anomaly);
        }

        $this->info($changed === 0 ? 'Aucun changement : les plans sont à jour.' : $changed.' plan(s) mis à jour.');

        return self::SUCCESS;
    }
}
