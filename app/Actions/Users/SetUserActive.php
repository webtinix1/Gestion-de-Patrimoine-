<?php

namespace App\Actions\Users;

use App\Models\User;

/**
 * RG-84 : un compte se désactive, il ne se supprime pas. Il ne peut plus se connecter
 * et garde son historique.
 */
class SetUserActive
{
    public function __invoke(User $user, bool $active): void
    {
        $user->is_active = $active;
        $user->save();
    }
}
