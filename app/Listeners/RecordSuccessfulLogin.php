<?php

namespace App\Listeners;

use App\Models\User;
use Illuminate\Auth\Events\Login;

/**
 * RG-70, RG-71 : la connexion est tracée avec le poste (adresse IP locale).
 */
class RecordSuccessfulLogin
{
    public function handle(Login $event): void
    {
        if (! $event->user instanceof User) {
            return;
        }

        $user = $event->user;
        $user->forceFill([
            'failed_attempts' => 0,
            'locked_until' => null,
            'last_login_at' => now(),
            'last_login_ip' => request()->ip(),
        ])->saveQuietly();

        activity('connexion')
            ->causedBy($user)
            ->performedOn($user)
            ->event('login')
            ->log('Connexion');
    }
}
