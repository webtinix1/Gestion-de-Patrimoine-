<?php

namespace App\Services\FiscalYearClosing;

use App\Models\FiscalYear;

/**
 * Source de points d'attention avant clôture (RG-42). Les lots Inventaires et Sorties remplacent
 * leur fournisseur provisoire par une vraie vérification.
 */
interface AttentionPointProvider
{
    /**
     * @return list<AttentionPoint>
     */
    public function points(FiscalYear $fiscalYear): array;
}
