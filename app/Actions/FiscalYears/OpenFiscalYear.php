<?php

namespace App\Actions\FiscalYears;

use App\Enums\FiscalYearStatus;
use App\Models\FiscalYear;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Ouvre un exercice préparé (E33).
 */
class OpenFiscalYear
{
    public function __invoke(FiscalYear $fiscalYear): FiscalYear
    {
        return DB::transaction(function () use ($fiscalYear): FiscalYear {
            $current = FiscalYear::query()->lockForUpdate()->findOrFail($fiscalYear->getKey());

            if ($current->status !== FiscalYearStatus::Prepared) {
                throw ValidationException::withMessages(['year' => 'Seul un exercice préparé peut être ouvert.']);
            }

            $current->update(['status' => FiscalYearStatus::Open]);

            activity('amortissements')
                ->performedOn($current)
                ->event('fiscal_year_opened')
                ->withProperties(['old' => ['status' => FiscalYearStatus::Prepared->getLabel()], 'attributes' => ['status' => FiscalYearStatus::Open->getLabel()]])
                ->log('Ouverture de l\'exercice '.$current->year);

            $fiscalYear->setRawAttributes($current->getAttributes(), sync: true);

            return $fiscalYear;
        });
    }
}
