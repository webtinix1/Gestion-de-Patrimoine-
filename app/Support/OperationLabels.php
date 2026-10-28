<?php

namespace App\Support;

use App\Enums\LoanDirection;
use App\Models\Asset;
use App\Models\AssetReport;
use App\Models\Loan;
use App\Models\MaintenanceJob;
use App\Models\Movement;
use Illuminate\Database\Eloquent\Model;

/**
 * Libellés français des objets liés à une opération (signalement traité, journal) : jamais « Movement n° 12 ».
 */
final class OperationLabels
{
    /**
     * @param  bool  $asOperation  true : l'objet est l'opération créée pour traiter un signalement ; false : sujet du journal
     */
    public static function for(Model $model, bool $asOperation = false): string
    {
        return match (true) {
            $model instanceof Movement => 'Transfert du '.$model->moved_on->format('d/m/Y'),
            $model instanceof Asset => ($asOperation ? 'Mise à jour de la fiche ' : 'Bien ').$model->number,
            $model instanceof Loan => ($model->direction === LoanDirection::In ? 'Emprunt ' : 'Prêt ').$model->reference,
            $model instanceof MaintenanceJob => 'Intervention '.$model->reference,
            $model instanceof AssetReport => 'Signalement '.$model->reference,
            default => class_basename($model).' n° '.$model->getKey(),
        };
    }
}
