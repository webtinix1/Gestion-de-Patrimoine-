<?php

namespace App\Services\Depreciation;

/**
 * Ligne d'un exercice clôturé ou antérieur : le calculateur la reprend telle quelle (RG-36).
 */
final readonly class FixedLine
{
    public function __construct(
        public int $year,
        public int $amount,
        public ?int $days = null,
        public ?int $months = null,
    ) {}
}
