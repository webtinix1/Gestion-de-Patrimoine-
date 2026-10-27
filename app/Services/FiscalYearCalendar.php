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

    public function lastClosedYear(): ?int
    {
        $closed = $this->all()->filter(fn (FiscalYear $year): bool => $year->isClosed());

        return $closed->isEmpty() ? null : (int) $closed->keys()->max();
    }

    /**
     * RG-36, RG-37 : premier exercice que l'on peut recalculer. Tout exercice jusqu'au dernier
     * exercice clôturé inclus est figé, même si un exercice antérieur non clôturé existe.
     * Sans exercice clôturé : le premier exercice existant (les années d'avant sont antérieures).
     * Sans aucun exercice, rien n'est figé (null).
     */
    public function firstRecalculableYear(): ?int
    {
        $lastClosed = $this->lastClosedYear();

        if ($lastClosed !== null) {
            return $lastClosed + 1;
        }

        $first = $this->all()->first();

        return $first instanceof FiscalYear ? $first->year : null;
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
