<?php

namespace App\Actions\Users;

use App\Models\User;

/**
 * RG-85 : réinitialisation par l'administrateur (pas d'e-mail en V1). Le compte est débloqué
 * et l'utilisateur doit changer le mot de passe provisoire à la connexion suivante.
 */
class ResetUserPassword
{
    public function __construct(private GenerateTemporaryPassword $generator) {}

    public function __invoke(User $user): string
    {
        $password = ($this->generator)();

        $user->forceFill([
            'password' => $password,
            'must_change_password' => true,
            'failed_attempts' => 0,
            'locked_until' => null,
        ])->saveQuietly();

        activity('utilisateurs')
            ->performedOn($user)
            ->event('password_reset')
            ->log('Réinitialisation du mot de passe');

        return $password;
    }
}
