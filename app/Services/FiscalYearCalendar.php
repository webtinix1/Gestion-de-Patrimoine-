<?php

namespace App\Services;

use App\Enums\DepreciationLineStatus;
use App\Enums\FiscalYearStatus;
use App\Models\FiscalYear;
use Illuminate\Support\Collection;

/**
 * Lecture des exercices comptables pour les plans (RG-36, RG-37). Une instance garde les exercices
 * en mémoire le temps d'un traitement ; en créer une nouvelle après une clôture.
 */
class FiscalYearCalendar
{
    /** @var Collection<int, FiscalYear>|null */
    private ?Collection $years = null;

    /**
     * @return Collection<int, FiscalYear> exercices indexés par année
     */
    public function all(): Collection
    {
        return $this->years ??= FiscalYear::query()->orderBy('year')->get()->keyBy('year');
    }

    public function find(int $year): ?FiscalYear
    {
        return $this->all()->get($year);
    }

    /**
     * RG-36, RG-37 : premier exercice que l'on peut recalculer (premier exercice non clôturé).
     * Les exercices antérieurs sont figés. Sans aucun exercice, rien n'est figé (null).
     */
    public function firstRecalculableYear(): ?int
    {
        $years = $this->all();

        $notClosed = $years->first(fn (FiscalYear $year): bool => ! $year->isClosed());

        if ($notClosed instanceof FiscalYear) {
            return $notClosed->year;
        }

        $lastClosed = $years->last();

        return $lastClosed instanceof FiscalYear ? $lastClosed->year + 1 : null;
    }

    /**
     * Exercice en cours : le premier exercice ouvert.
     */
    public function current(): ?FiscalYear
    {
        return $this->all()->first(fn (FiscalYear $year): bool => $year->status === FiscalYearStatus::Open);
    }

    public function lineStatus(int $year): DepreciationLineStatus
    {
        $fiscalYear = $this->find($year);

        if ($fiscalYear instanceof FiscalYear) {
            return match ($fiscalYear->status) {
                FiscalYearStatus::Closed => DepreciationLineStatus::Closed,
                FiscalYearStatus::Open => DepreciationLineStatus::Open,
                FiscalYearStatus::Prepared => DepreciationLineStatus::Prepared,
            };
        }

        $first = $this->firstRecalculableYear();

        return $first !== null && $year < $first ? DepreciationLineStatus::Prior : DepreciationLineStatus::Forecast;
    }
}
