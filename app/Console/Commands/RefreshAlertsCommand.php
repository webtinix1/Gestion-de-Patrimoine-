<?php

namespace App\Console\Commands;

use App\Actions\Alerts\RefreshAlerts;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * RG-90, RG-91 : calcule les alertes (planifié toutes les heures). Idempotent : relancer ne crée aucun doublon.
 */
#[Signature('patrimoine:calculer-alertes')]
#[Description('Calcule les alertes internes (prêts, maintenance, hors service, inventaires, amortissements)')]
class RefreshAlertsCommand extends Command
{
    public function handle(RefreshAlerts $refresh): int
    {
        $report = $refresh();

        $this->table(['Résultat', 'Alertes'], [
            ['Nouvelles', $report['raised']],
            ['Déjà connues', $report['kept']],
            ['Levées (cause disparue)', $report['resolved']],
            ['Purgées (levées depuis plus de 30 jours)', $report['purged']],
        ]);

        return self::SUCCESS;
    }
}
