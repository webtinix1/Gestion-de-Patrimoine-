<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Filament\Facades\Filament;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * RG-85 : un mot de passe provisoire doit être changé avant d'utiliser l'application.
 */
class EnsurePasswordIsChanged
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user instanceof User || ! $user->must_change_password) {
            return $next($request);
        }

        $profileUrl = Filament::getProfileUrl();

        if ($profileUrl === null
            || $request->is('livewire*/*')
            || $request->url() === $profileUrl
            || $request->routeIs('filament.*.auth.logout')) {
            return $next($request);
        }

        return redirect($profileUrl);
    }
}
