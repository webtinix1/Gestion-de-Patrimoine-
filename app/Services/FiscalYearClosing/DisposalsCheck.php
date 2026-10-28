<?php

namespace App\Services\FiscalYearClosing;

use App\Enums\DisposalAccountingStatus;
use App\Models\Disposal;
use App\Models\FiscalYear;

/**
 * RG-42 : sorties de l'exercice (ou antérieures) dont le traitement comptable n'est pas validé (RG-54).
 * Toutes les sorties comptent, quel que soit le périmètre de la personne qui clôture.
 */
class DisposalsCheck implements AttentionPointProvider
{
    private const int SHOWN = 10;

    public function points(FiscalYear $fiscalYear): array
    {
        $pending = Disposal::query()->withoutGlobalScopes()
            ->where('accounting_status', DisposalAccountingStatus::Pending)
            ->whereDate('disposed_on', '<=', $fiscalYear->ends_on->toDateString())
            ->orderBy('reference')
            ->pluck('reference');

        if ($pending->isEmpty()) {
            return [new AttentionPoint('disposals', 'Toutes les sorties de l\'exercice '.$fiscalYear->year.' sont validées par le comptable', AttentionPoint::OK)];
        }

        $count = $pending->count();
        $label = $count.' sortie'.($count > 1 ? 's' : '').' non validée'.($count > 1 ? 's' : '').' par le comptable : '
            .$pending->take(self::SHOWN)->implode(', ')
            .($count > self::SHOWN ? ' et '.($count - self::SHOWN).' autre(s)' : '');

        return [new AttentionPoint('disposals', $label, AttentionPoint::WARNING)];
    }
}
