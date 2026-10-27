<?php

namespace App\Services\Depreciation;

use DomainException;

/**
 * Les paramètres demandés ne permettent pas de plan sans modifier un exercice figé (RG-34, RG-36).
 */
class ImpossibleDepreciationPlan extends DomainException {}
