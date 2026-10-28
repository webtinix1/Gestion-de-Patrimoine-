<?php

namespace App\Services\Depreciation;

use DomainException;

/**
 * Les paramètres demandés ne permettent pas de plan sans modifier un exercice figé (RG-34, RG-36).
 * Le message est destiné à l'utilisateur ; le champ désigne le paramètre de la fiche en cause.
 */
class ImpossibleDepreciationPlan extends DomainException
{
    public function __construct(string $message, public readonly string $field = 'duration_years')
    {
        parent::__construct($message);
    }
}
