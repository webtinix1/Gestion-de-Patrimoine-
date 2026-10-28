<?php

namespace App\Http\Middleware;

use App\Services\Settings;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
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

    public const string CACHE_KEY = 'settings.session_lifetime_minutes';

    public const int CACHE_SECONDS = 60;

    public function handle(Request $request, Closure $next): Response
    {
        try {
            // 0 : aucune valeur enregistrée. Cache court, vidé par Settings::set, pour ne pas interroger la base à chaque requête.
            $minutes = (int) Cache::remember(self::CACHE_KEY, self::CACHE_SECONDS, function (): int {
                $settings = app(Settings::class);

                return $settings->has(Settings::SESSION_LIFETIME_MINUTES) ? $settings->int(Settings::SESSION_LIFETIME_MINUTES) : 0;
            });

            if ($minutes >= self::MIN_MINUTES && $minutes <= self::MAX_MINUTES) {
                config(['session.lifetime' => $minutes]);
            }
        } catch (Throwable) {
            // Base injoignable ou pas encore migrée (installation) : la valeur du fichier .env reste en vigueur.
        }

        return $next($request);
    }
}
