<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

/**
 * RG-80 : droits de la matrice (consulter, créer, modifier, sortir, valider, exporter).
 */
enum PermissionAbility: string implements HasLabel
{
    case View = 'view';
    case Create = 'create';
    case Update = 'update';
    case Delete = 'delete';
    case Validate = 'validate';
    case Export = 'export';

    public function getLabel(): string
    {
        return match ($this) {
            self::View => 'Consulter',
            self::Create => 'Créer',
            self::Update => 'Modifier',
            self::Delete => 'Supprimer / sortir',
            self::Validate => 'Valider',
            self::Export => 'Exporter',
        };
    }
}
