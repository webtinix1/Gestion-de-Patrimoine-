<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * En-têtes de sécurité de toutes les réponses web : pas d'affichage dans un cadre d'un autre site
 * (clickjacking), pas de devinette du type de contenu, pas d'adresse transmise à un autre site.
 * La CSP se limite à frame-ancestors : Filament et Livewire s'appuient sur des scripts en ligne.
 * Un en-tête déjà posé (CSP « sandbox » des fichiers servis) n'est pas remplacé.
 */
class AddSecurityHeaders
{
    /**
     * @var array<string, string>
     */
    public const array HEADERS = [
        'X-Frame-Options' => 'SAMEORIGIN',
        'Content-Security-Policy' => "frame-ancestors 'self'",
        'X-Content-Type-Options' => 'nosniff',
        'Referrer-Policy' => 'same-origin',
        'Permissions-Policy' => 'geolocation=(), microphone=(), payment=()',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        foreach (self::HEADERS as $name => $value) {
            if (! $response->headers->has($name)) {
                $response->headers->set($name, $value);
            }
        }

        return $response;
    }
}
