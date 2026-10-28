<?php

namespace App\Services\Depreciation;

use App\Enums\DepreciationMethod;
use Carbon\CarbonImmutable;

/**
 * Entrée du calculateur d'amortissement : tout ce qui détermine un plan, sans accès à la base
 * ni à l'horloge. Montants en FCFA entiers ; coefficient dégressif en décimal (« 2.0000 »).
 */
final readonly class DepreciationInput
{
    /**
     * @param  array<int, FixedLine>  $fixedLines  lignes figées (exercices clôturés ou antérieurs), par exercice
     * @param  int|null  $recalculatedFromYear  RG-37 : premier exercice ouvert au moment du dernier changement de paramètre
     * @param  int  $capitalizationThreshold  RG-30 : en dessous de ce prix, aucun plan (0 = pas de seuil)
     */
    public function __construct(
        public DepreciationMethod $method,
        public int $originalValue,
        public int $residualValue,
        public int $durationYears,
        public CarbonImmutable $inServiceOn,
        public ?string $decliningCoefficient = null,
        public ?CarbonImmutable $disposedOn = null,
        public array $fixedLines = [],
        public ?int $recalculatedFromYear = null,
        public int $capitalizationThreshold = 0,
    ) {}

    public function base(): int
    {
        return $this->originalValue - $this->residualValue;
    }
}
