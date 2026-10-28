<?php

namespace App\Services;

/**
 * Valeurs d'un bien à sa date de sortie (RG-39). VNC = valeur d'origine − amortissements cumulés.
 */
final readonly class DisposalValues
{
    public function __construct(
        public int $originalValue,
        public int $accumulatedDepreciation,
        public int $netBookValue,
        // Dotation de l'exercice de sortie, arrêtée à la date de sortie incluse (null : pas de plan).
        public ?int $yearAmount,
        public bool $hasPlan,
    ) {}

    /**
     * Bien sans plan d'amortissement (non amortissable, ou sans date de mise en service) : rien n'est
     * amorti, la VNC est la valeur d'origine (QL10-2).
     */
    public static function withoutPlan(int $originalValue): self
    {
        return new self($originalValue, 0, $originalValue, null, false);
    }

    /**
     * @param  list<array{0: int, 1: int, 2: int}>  $lines  exercice, dotation, cumul, dans l'ordre des exercices
     */
    public static function fromLines(int $originalValue, array $lines, int $year): self
    {
        $accumulated = 0;
        $yearAmount = 0;

        foreach ($lines as [$lineYear, $amount, $lineAccumulated]) {
            if ($lineYear > $year) {
                break;
            }

            $accumulated = $lineAccumulated;
            $yearAmount = $lineYear === $year ? $amount : 0;
        }

        return new self($originalValue, $accumulated, $originalValue - $accumulated, $yearAmount, true);
    }

    /**
     * Q8 : plus-value (positive) ou moins-value (négative) indicative d'une cession, sans écriture.
     */
    public function indicativeGain(?int $salePrice): ?int
    {
        return $salePrice === null ? null : $salePrice - $this->netBookValue;
    }
}
