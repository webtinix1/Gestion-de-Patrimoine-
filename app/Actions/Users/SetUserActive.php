<?php

namespace App\Actions\Users;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * RG-84 : un compte se désactive, il ne se supprime pas. Il ne peut plus se connecter
 * et garde son historique.
 */
class SetUserActive
{
    public function __invoke(User $user, bool $active): void
    {
        if (! $active && $user->isAdministrator() && ! SyncUserAccess::otherActiveAdministratorExists($user)) {
            throw ValidationException::withMessages(['is_active' => 'Il doit rester au moins un administrateur actif.']);
        }

        $user->is_active = $active;
        $user->save();

        // Les sessions ouvertes d'un compte désactivé sont fermées tout de suite.
        if (! $active && config('session.driver') === 'database') {
            DB::table(config('session.table', 'sessions'))->where('user_id', $user->getKey())->delete();
        }
    }
}
