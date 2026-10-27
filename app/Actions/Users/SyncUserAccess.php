<?php

namespace App\Actions\Users;

use App\Models\Department;
use App\Models\Site;
use App\Models\User;

/**
 * RG-80, RG-81 : rôle et périmètre d'un utilisateur, tracés avec avant et après (RG-70).
 */
class SyncUserAccess
{
    /**
     * @param  list<int|string>  $siteIds  vide = tous les sites
     * @param  list<int|string>  $departmentIds  vide = tous les départements
     */
    public function __invoke(User $user, string $role, array $siteIds, array $departmentIds): void
    {
        $before = $this->snapshot($user);

        $user->syncRoles([$role]);
        $user->sites()->sync(array_map('intval', $siteIds));
        $user->departments()->sync(array_map('intval', $departmentIds));
        $user->forgetPerimeter();

        $after = $this->snapshot($user->refresh());

        if ($before === $after) {
            return;
        }

        activity('utilisateurs')
            ->performedOn($user)
            ->event('access_updated')
            ->withProperties(['old' => $before, 'attributes' => $after])
            ->log('Modification des droits d\'un utilisateur');
    }

    /**
     * @return array{rôle: string, sites: string, départements: string}
     */
    private function snapshot(User $user): array
    {
        $sites = Site::query()->withoutGlobalScopes()->whereIn('id', $user->sites()->allRelatedIds())->orderBy('name')->pluck('name');
        $departments = Department::query()->withoutGlobalScopes()->whereIn('id', $user->departments()->allRelatedIds())->orderBy('name')->pluck('name');

        return [
            'rôle' => $user->getRoleNames()->join(', '),
            'sites' => $sites->isEmpty() ? 'Tous' : $sites->join(', '),
            'départements' => $departments->isEmpty() ? 'Tous' : $departments->join(', '),
        ];
    }
}
