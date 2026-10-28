<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// RG-90 : les alertes sont recalculées toutes les heures (docs/07 : tâche `schedule:run` chaque minute).
Schedule::command('patrimoine:calculer-alertes')->hourly()->withoutOverlapping();
