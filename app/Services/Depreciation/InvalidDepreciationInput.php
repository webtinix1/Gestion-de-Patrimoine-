<?php

namespace App\Services\Depreciation;

use InvalidArgumentException;

/**
 * Paramètres d'amortissement invalides, avec le champ de la fiche concerné et un message métier.
 */
class InvalidDepreciationInput extends InvalidArgumentException
{
    public function __construct(string $message, public readonly string $field = 'duration_years')
    {
        parent::__construct($message);
    }
}
