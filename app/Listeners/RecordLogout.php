<?php

namespace App\Listeners;

use App\Models\User;
use Illuminate\Auth\Events\Logout;

/**
 * RG-70, RG-71 : la déconnexion est tracée comme la connexion.
 */
class RecordLogout
{
    public function handle(Logout $event): void
    {
        if (! $event->user instanceof User) {
            return;
        }

        activity('connexion')
            ->causedBy($event->user)
            ->performedOn($event->user)
            ->event('logout')
            ->log('Déconnexion');
    }
}
