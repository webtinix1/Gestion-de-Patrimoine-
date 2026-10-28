<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

/** RG-44 : périmètre d'un inventaire, éventuellement croisé avec un site. */
enum InventoryScopeType: string implements HasLabel
{
    case Site = 'site';
    case Department = 'department';
    case Service = 'service';
    case Category = 'category';
    case Room = 'room';
    case All = 'all';

    public function getLabel(): string
    {
        return match ($this) {
            self::Site => 'Un site',
            self::Department => 'Un département',
            self::Service => 'Un service',
            self::Category => 'Une catégorie',
            self::Room => 'Un local',
            self::All => 'Tout le patrimoine',
        };
    }

    public function valueLabel(): string
    {
        return match ($this) {
            self::Site => 'Site',
            self::Department => 'Département',
            self::Service => 'Service',
            self::Category => 'Catégorie',
            self::Room => 'Local',
            self::All => '',
        };
    }

    public function needsValue(): bool
    {
        return $this !== self::All;
    }

    // Un site ou un local porte déjà son site : pas de filtre croisé.
    public function allowsSiteFilter(): bool
    {
        return ! in_array($this, [self::Site, self::Room], true);
    }

    /** Colonne de la table des biens qui porte ce périmètre. */
    public function assetColumn(): ?string
    {
        return match ($this) {
            self::Site => 'site_id',
            self::Department => 'department_id',
            self::Service => 'service_id',
            self::Category => 'asset_category_id',
            self::Room => 'room_id',
            self::All => null,
        };
    }
}
