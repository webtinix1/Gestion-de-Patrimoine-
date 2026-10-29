<?php

namespace App\Actions\Users;

use App\Models\Department;
use App\Models\Site;
use App\Models\User;
use App\Support\DefaultRoles;
use Illuminate\Validation\ValidationException;

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
        $siteIds = array_map('intval', $siteIds);
        $departmentIds = array_map('intval', $departmentIds);

        $this->guard($user, $role, $siteIds, $departmentIds);

        $before = $this->snapshot($user);

        $user->syncRoles([$role]);
        $user->sites()->sync($siteIds);
        $user->departments()->sync($departmentIds);
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

    public static function otherActiveAdministratorExists(User $user): bool
    {
        return User::role(DefaultRoles::ADMIN)->where('is_active', true)->whereKeyNot($user->getKey())->exists();
    }

    /**
     * Garde-fous contre l'élévation de privilèges, revérifiés côté serveur quel que soit le formulaire :
     * on ne change ni son propre rôle ni son propre périmètre, seul un administrateur attribue le rôle
     * Administrateur, un utilisateur limité n'accorde pas un périmètre plus large que le sien, et il
     * reste toujours un administrateur actif. Les clés d'erreur sont celles du formulaire Filament.
     *
     * @param  list<int>  $siteIds
     * @param  list<int>  $departmentIds
     */
    private function guard(User $user, string $role, array $siteIds, array $departmentIds): void
    {
        $errors = [];
        $actor = auth()->user();

        if ($actor instanceof User) {
            if ($user->exists && $actor->is($user) && $this->changes($user, $role, $siteIds, $departmentIds)) {
                $errors['data.role'] = 'Vous ne pouvez modifier ni votre propre rôle ni votre propre périmètre.';
            }

            if ($role === DefaultRoles::ADMIN && ! $actor->isAdministrator()) {
                $errors['data.role'] = 'Seul un administrateur peut attribuer le rôle Administrateur.';
            }

            if (! $this->within($siteIds, $actor->perimeterSiteIds())) {
                $errors['data.sites'] = 'Les sites accordés ne peuvent pas dépasser votre propre périmètre.';
            }

            if (! $this->within($departmentIds, $actor->perimeterDepartmentIds())) {
                $errors['data.departments'] = 'Les départements accordés ne peuvent pas dépasser votre propre périmètre.';
            }
        }

        if ($role !== DefaultRoles::ADMIN && $user->exists && $user->isAdministrator() && ! self::otherActiveAdministratorExists($user)) {
            $errors['data.role'] = 'Il doit rester au moins un administrateur actif.';
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }

    /**
     * @param  list<int>  $siteIds
     * @param  list<int>  $departmentIds
     */
    private function changes(User $user, string $role, array $siteIds, array $departmentIds): bool
    {
        $currentSites = $user->perimeterSiteIds() ?? [];
        $currentDepartments = $user->perimeterDepartmentIds() ?? [];
        sort($siteIds);
        sort($departmentIds);
        sort($currentSites);
        sort($currentDepartments);

        return $user->getRoleNames()->first() !== $role || $siteIds !== $currentSites || $departmentIds !== $currentDepartments;
    }

    /**
     * Vide = tous : un utilisateur limité ne peut donc pas l'accorder.
     *
     * @param  list<int>  $granted
     * @param  list<int>|null  $own  null si tous
     */
    private function within(array $granted, ?array $own): bool
    {
        return $own === null || ($granted !== [] && array_diff($granted, $own) === []);
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
