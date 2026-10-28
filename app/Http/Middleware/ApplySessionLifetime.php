<?php

namespace App\Http\Middleware;

use App\Services\Settings;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * RG-85, E41 : la durée d'inactivité avant déconnexion se règle dans les paramètres. Elle remplace
 * `session.lifetime` avant le démarrage de la session (donc avant la création du gestionnaire de
 * session et du cookie). Sans valeur enregistrée, SESSION_LIFETIME du fichier .env s'applique.
 */
class ApplySessionLifetime
{
    public const int MIN_MINUTES = 5;

    public const int MAX_MINUTES = 480;

    public function handle(Request $request, Closure $next): Response
    {
        try {
            $settings = app(Settings::class);

            if ($settings->has(Settings::SESSION_LIFETIME_MINUTES)) {
                $minutes = $settings->int(Settings::SESSION_LIFETIME_MINUTES);

                if ($minutes >= self::MIN_MINUTES && $minutes <= self::MAX_MINUTES) {
                    config(['session.lifetime' => $minutes]);
                }
            }
        } catch (Throwable) {
            // Base injoignable ou pas encore migrée (installation) : la valeur du fichier .env reste en vigueur.
        }

        return $next($request);
    }
}
