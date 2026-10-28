<?php

namespace App\Services\FiscalYearClosing;

use App\Models\FiscalYear;

/**
 * RG-42 : points d'attention affichés avant la clôture. Ils n'empêchent pas la clôture,
 * mais ceux qui ne sont pas « ok » doivent être confirmés.
 */
class FiscalYearAttentionPoints
{
    /**
     * @return list<AttentionPointProvider>
     */
    public function providers(): array
    {
        return [
            app(DepreciationPlansCheck::class),
            app(PlanAnomaliesCheck::class),
            app(DisposalsCheck::class),
            // Remplacé par une vraie vérification au lot 9 (inventaires).
            PendingModuleCheck::inventories(),
        ];
    }

    /**
     * @return list<AttentionPoint>
     */
    public function for(FiscalYear $fiscalYear): array
    {
        $points = [];

        foreach ($this->providers() as $provider) {
            array_push($points, ...$provider->points($fiscalYear));
        }

        return $points;
    }

    /**
     * @param  list<AttentionPoint>  $points
     */
    public static function needConfirmation(array $points): bool
    {
        foreach ($points as $point) {
            if ($point->needsConfirmation()) {
                return true;
            }
        }

        return false;
    }
}
