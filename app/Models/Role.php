<?php

namespace App\Models;

use App\Support\DefaultRoles;
use Spatie\Permission\Models\Role as SpatieRole;

class Role extends SpatieRole
{
    public function isDefault(): bool
    {
        return in_array($this->name, DefaultRoles::names(), true);
    }

    // Le rôle Administrateur garde toujours tous les droits.
    public function isLocked(): bool
    {
        return $this->name === DefaultRoles::ADMIN;
    }

    public function description(): ?string
    {
        return DefaultRoles::DESCRIPTIONS[$this->name] ?? null;
    }
}
