<?php

namespace App\Services\FiscalYearClosing;

use App\Models\FiscalYear;

/**
 * RG-42 : vérification d'un module qui n'existe pas encore (sorties : lot 10, inventaires : lot 9).
 * Le point reste affiché et doit être confirmé ; le lot concerné remplace ce fournisseur.
 */
class PendingModuleCheck implements AttentionPointProvider
{
    public function __construct(
        private string $key,
        private string $label,
    ) {}

    public static function disposals(): self
    {
        return new self('disposals', 'Sorties non validées par le comptable : vérification impossible, le module Sorties n\'est pas encore livré');
    }

    public static function inventories(): self
    {
        return new self('inventories', 'Inventaires non clôturés : vérification impossible, le module Inventaires n\'est pas encore livré');
    }

    public function points(FiscalYear $fiscalYear): array
    {
        return [new AttentionPoint($this->key, $this->label, AttentionPoint::UNAVAILABLE)];
    }
}
