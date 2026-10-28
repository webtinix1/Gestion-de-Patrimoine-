<?php

namespace App\Policies;

use App\Enums\PermissionAbility;
use App\Enums\PermissionModule;
use App\Models\Activity;
use App\Models\User;

/**
 * RG-72 : le journal se consulte, il ne se modifie ni ne se supprime.
 */
class ActivityPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->is_active && $user->can(PermissionModule::ActivityLog->permission(PermissionAbility::View));
    }

    public function view(User $user, Activity $activity): bool
    {
        return $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, Activity $activity): bool
    {
        return false;
    }

    public function delete(User $user, Activity $activity): bool
    {
        return false;
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }
}
