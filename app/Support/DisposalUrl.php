<?php

namespace App\Support;

use Illuminate\Support\Facades\Route;

/**
 * Contrat avec la page des sorties du patrimoine (lot 10) : /admin/sorties/create?bien=<id>&motif=retirement.
 * Le lien n'est proposé que si cette page existe dans l'application ; le résultat est gardé pour la requête.
 */
final class DisposalUrl
{
    public const string CACHE_KEY = 'operations.disposal-page-exists';

    public static function pageExists(): bool
    {
        $app = app();

        if ($app->bound(self::CACHE_KEY)) {
            return (bool) $app->make(self::CACHE_KEY);
        }

        $exists = false;

        foreach (Route::getRoutes()->getRoutes() as $route) {
            if ($route->uri() === 'admin/sorties/create') {
                $exists = true;

                break;
            }
        }

        $app->instance(self::CACHE_KEY, $exists);

        return $exists;
    }

    public static function forget(): void
    {
        app()->forgetInstance(self::CACHE_KEY);
    }

    public static function for(int $assetId): string
    {
        return url('/admin/sorties/create').'?'.http_build_query(['bien' => $assetId, 'motif' => 'retirement']);
    }
}
