<?php

use App\Services\System\ActivityLogProtection;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Log;

/**
 * RG-70 : le journal des opérations ne se modifie ni ne se supprime, même hors de l'application.
 * Si MySQL refuse de créer les déclencheurs (journal binaire actif, compte sans privilège SUPER),
 * l'installation continue : le modèle protège toujours le journal, et `patrimoine:verifier` le signale.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! app(ActivityLogProtection::class)->install()) {
            Log::warning('Protection du journal en base non posée. '.ActivityLogProtection::ADVICE);
        }
    }

    public function down(): void
    {
        app(ActivityLogProtection::class)->remove();
    }
};
