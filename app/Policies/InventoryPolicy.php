<?php

namespace App\Policies;

use App\Enums\InventoryStatus;
use App\Enums\PermissionAbility;
use App\Enums\PermissionModule;
use App\Models\Inventory;
use App\Models\User;
use App\Services\Inventories\InventoryScope;

/**
 * Inventaires (E18 à E21).
 *
 * - Organiser (créer, modifier un inventaire planifié, le démarrer) : droits « créer » et « modifier »,
 *   inventaire contenu dans le périmètre de l'utilisateur (RG-81).
 * - Saisir (RG-47, RG-82) : droit « modifier », inventaire en cours, et être désigné (responsable ou
 *   participant) ou avoir le droit « créer » (gestionnaire). Les lignes restent limitées au périmètre.
 * - Terminer la saisie, rouvrir, valider et clôturer (RG-48), régulariser : inventaire contenu dans le
 *   périmètre de l'utilisateur (une personne seulement désignée ne valide pas) ; « valider » pour rouvrir
 *   et clôturer ; « modifier » plus « créer » ou « valider » pour régulariser (QL9-7).
 * - Un inventaire ne se supprime jamais.
 */
class InventoryPolicy
{
    public function viewAny(User $user): bool
    {
        return $this->allows($user, PermissionAbility::View);
    }

    public function view(User $user, Inventory $inventory): bool
    {
        return $this->allows($user, PermissionAbility::View);
    }

    public function create(User $user): bool
    {
        return $this->allows($user, PermissionAbility::Create) && $this->allows($user, PermissionAbility::Update);
    }

    public function update(User $user, Inventory $inventory): bool
    {
        return $inventory->isPlanned() && $this->organizes($user, $inventory);
    }

    public function start(User $user, Inventory $inventory): bool
    {
        return $inventory->isPlanned() && $this->organizes($user, $inventory);
    }

    public function enter(User $user, Inventory $inventory): bool
    {
        return $inventory->isInProgress()
            && $this->allows($user, PermissionAbility::Update)
            && ($this->allows($user, PermissionAbility::Create) || $inventory->isDesignated($user));
    }

    // Terminer la saisie arrête la saisie de tous : réservé à qui couvre tout l'inventaire (RG-81).
    public function submit(User $user, Inventory $inventory): bool
    {
        return $this->enter($user, $inventory) && $this->contains($user, $inventory);
    }

    public function reopen(User $user, Inventory $inventory): bool
    {
        return $inventory->status === InventoryStatus::ToValidate && $this->allows($user, PermissionAbility::Validate)
            && $this->contains($user, $inventory);
    }

    // RG-48, RG-81 : valider engage tout l'inventaire ; une personne seulement désignée ne valide pas.
    public function close(User $user, Inventory $inventory): bool
    {
        return $inventory->status === InventoryStatus::ToValidate && $this->allows($user, PermissionAbility::Validate)
            && $this->contains($user, $inventory);
    }

    public function regularize(User $user, Inventory $inventory): bool
    {
        // QL9-7 : le responsable de département saisit mais ne régularise pas.
        return $inventory->isClosed() && $this->allows($user, PermissionAbility::Update)
            && ($this->allows($user, PermissionAbility::Create) || $this->allows($user, PermissionAbility::Validate))
            && $this->contains($user, $inventory);
    }

    public function export(User $user, Inventory $inventory): bool
    {
        return $inventory->started_at !== null && $this->allows($user, PermissionAbility::Export);
    }

    public function delete(User $user, Inventory $inventory): bool
    {
        return false;
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }

    public function restore(User $user, Inventory $inventory): bool
    {
        return false;
    }

    public function forceDelete(User $user, Inventory $inventory): bool
    {
        return false;
    }

    private function organizes(User $user, Inventory $inventory): bool
    {
        return $this->create($user) && $this->contains($user, $inventory);
    }

    private function contains(User $user, Inventory $inventory): bool
    {
        return app(InventoryScope::class)->isContainedIn($inventory, $user);
    }

    private function allows(User $user, PermissionAbility $ability): bool
    {
        return $user->is_active && $user->can(PermissionModule::Inventories->permission($ability));
    }
}
