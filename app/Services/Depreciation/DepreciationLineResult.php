<?php

namespace App\Services\Depreciation;

/**
 * Une ligne du plan : un exercice. Taux en pourcentage décimal à 4 chiffres (« 33.3333 »).
 */
final readonly class DepreciationLineResult
{
    public function __construct(
        public int $year,
        public ?int $days,
        public ?int $months,
        public int $base,
        public string $rate,
        public int $amount,
        public int $accumulated,
        public int $netBookValue,
        public bool $fixed,
    ) {}
}
