<?php

namespace App\Actions\FiscalYears;

use App\Enums\FiscalYearStatus;
use App\Models\FiscalYear;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;

/**
 * Crée un exercice « préparé » (E33). Q5 : l'exercice est l'année civile, du 01/01 au 31/12.
 */
class CreateFiscalYear
{
    public function __invoke(int $year, FiscalYearStatus $status = FiscalYearStatus::Prepared): FiscalYear
    {
        if ($year < 1990 || $year > 2100) {
            throw ValidationException::withMessages(['year' => 'Année d\'exercice invalide.']);
        }

        if (FiscalYear::query()->where('year', $year)->exists()) {
            throw ValidationException::withMessages(['year' => 'L\'exercice '.$year.' existe déjà.']);
        }

        // RG-36 : un exercice ne s'insère pas avant les exercices existants, sinon des années déjà
        // closes ou antérieures redeviendraient recalculables.
        $last = FiscalYear::query()->max('year');

        if ($last !== null && $year < (int) $last) {
            throw ValidationException::withMessages([
                'year' => 'Un nouvel exercice se crée après le dernier exercice existant ('.$last.') : les exercices passés ne se rouvrent pas.',
            ]);
        }

        if ($status === FiscalYearStatus::Closed) {
            throw ValidationException::withMessages(['year' => 'Un exercice se clôture, il ne se crée pas clôturé.']);
        }

        $fiscalYear = FiscalYear::query()->create([
            'year' => $year,
            'label' => (string) $year,
            'starts_on' => CarbonImmutable::create($year, 1, 1)->toDateString(),
            'ends_on' => CarbonImmutable::create($year, 12, 31)->toDateString(),
            'status' => $status,
        ]);

        activity('amortissements')
            ->performedOn($fiscalYear)
            ->event('fiscal_year_created')
            ->withProperties(['attributes' => ['year' => $year, 'status' => $status->getLabel()]])
            ->log('Création de l\'exercice '.$year);

        return $fiscalYear;
    }
}
