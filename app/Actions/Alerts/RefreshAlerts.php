<?php

namespace App\Actions\Alerts;

use App\Services\Alerts\AlertCounter;
use App\Services\Alerts\AlertGenerator;
use App\Services\Settings;
use Carbon\CarbonImmutable;

/**
 * RG-90, RG-91 : recalcule les alertes de tout le patrimoine. Lancée toutes les heures par le planificateur
 * (commande patrimoine:calculer-alertes), jamais à l'affichage d'une page.
 */
class RefreshAlerts
{
    public function __construct(private AlertGenerator $generator) {}

    /**
     * @return array{raised: int, kept: int, resolved: int, purged: int}
     */
    public function __invoke(?CarbonImmutable $today = null): array
    {
        $report = $this->generator->refresh($today);
        app(Settings::class)->recordTechnical(Settings::ALERTS_LAST_RUN_AT, now()->toIso8601String());
        app(AlertCounter::class)->forget();

        return $report;
    }
}
