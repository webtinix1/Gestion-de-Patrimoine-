<?php

namespace App\Services\Depreciation;

use Carbon\CarbonImmutable;

/**
 * Résultat du calculateur : paramètres du plan et lignes par exercice.
 */
final readonly class DepreciationPlan
{
    /**
     * @param  list<DepreciationLineResult>  $lines
     */
    public function __construct(
        public int $base,
        public string $rate,
        public CarbonImmutable $startsOn,
        public CarbonImmutable $endsOn,
        public array $lines,
    ) {}

    public function totalAmount(): int
    {
        return array_sum(array_map(fn (DepreciationLineResult $line): int => $line->amount, $this->lines));
    }
}
