<?php

namespace Database\Seeders;

use App\Actions\Alerts\RefreshAlerts;
use Illuminate\Database\Seeder;

/**
 * Calcule une première fois les alertes à partir des données de démonstration (RG-90).
 */
class AlertsDemoSeeder extends Seeder
{
    public function run(): void
    {
        app(RefreshAlerts::class)();
    }
}
