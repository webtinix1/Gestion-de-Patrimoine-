<?php

namespace App\Listeners;

use App\Models\User;
use Illuminate\Auth\Events\Failed;

/**
 * RG-85 : blocage 15 minutes après 5 échecs consécutifs. Chaque échec est tracé avec le poste (RG-71).
 */
class RecordFailedLogin
{
    public const int MAX_ATTEMPTS = 5;

    public const int LOCK_MINUTES = 15;

    public function handle(Failed $event): void
    {
        if (! $event->user instanceof User) {
            // Identifiant inconnu : on ne journalise pas la saisie, qui peut être un mot de passe tapé au mauvais endroit.
            activity('connexion')
                ->event('failed')
                ->withProperties(['ip' => request()->ip()])
                ->log('Échec de connexion (identifiant inconnu)');

            return;
        }

        $user = $event->user;

        // Incrément atomique : deux essais simultanés comptent bien pour deux.
        User::query()->whereKey($user->getKey())->increment('failed_attempts');
        $attempts = (int) User::query()->whereKey($user->getKey())->value('failed_attempts');

        activity('connexion')
            ->performedOn($user)
            ->event('failed')
            ->withProperties(['ip' => request()->ip()])
            ->log('Échec de connexion');

        if ($attempts < self::MAX_ATTEMPTS) {
            return;
        }

        $user->forceFill([
            'failed_attempts' => 0,
            'locked_until' => now()->addMinutes(self::LOCK_MINUTES),
        ])->saveQuietly();

        activity('connexion')
            ->performedOn($user)
            ->event('locked')
            ->withProperties(['ip' => request()->ip()])
            ->log('Blocage du compte après '.self::MAX_ATTEMPTS.' échecs de connexion');
    }
}
