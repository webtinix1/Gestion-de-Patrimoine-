<?php

namespace App\Support;

use Illuminate\Support\Facades\Route;

/**
 * Contrat avec la page des sorties du patrimoine (lot 10) : /admin/sorties/create?bien=<id>&motif=retirement.
 * Le lien n'est proposé que si cette page existe dans l'application.
 */
final class DisposalUrl
{
    public static function pageExists(): bool
    {
        foreach (Route::getRoutes()->getRoutes() as $route) {
            if ($route->uri() === 'admin/sorties/create') {
                return true;
            }
        }

        return false;
    }

    public static function for(int $assetId): string
    {
        return url('/admin/sorties/create').'?'.http_build_query(['bien' => $assetId, 'motif' => 'retirement']);
    }
}
