<?php

namespace App\Listeners;

use App\Models\User;
use Illuminate\Auth\Events\Failed;

/**
 * RG-85 : blocage 15 minutes après 5 échecs consécutifs.
 */
class RecordFailedLogin
{
    public const int MAX_ATTEMPTS = 5;

    public const int LOCK_MINUTES = 15;

    public function handle(Failed $event): void
    {
        if (! $event->user instanceof User) {
            return;
        }

        $user = $event->user;
        $user->failed_attempts++;

        if ($user->failed_attempts >= self::MAX_ATTEMPTS) {
            $user->failed_attempts = 0;
            $user->locked_until = now()->addMinutes(self::LOCK_MINUTES);

            activity('connexion')
                ->performedOn($user)
                ->event('locked')
                ->log('Blocage du compte après '.self::MAX_ATTEMPTS.' échecs de connexion');
        }

        $user->saveQuietly();
    }
}
