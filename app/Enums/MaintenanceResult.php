<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

/** RG-61 : résultat d'une intervention ; « irréparable » propose une sortie du patrimoine. */
enum MaintenanceResult: string implements HasColor, HasLabel
{
    case Repaired = 'repaired';
    case Compliant = 'compliant';
    case Partial = 'partial';
    case Irreparable = 'irreparable';

    public function getLabel(): string
    {
        return match ($this) {
            self::Repaired => 'Réparé',
            self::Compliant => 'Conforme',
            self::Partial => 'Réparation partielle',
            self::Irreparable => 'Irréparable',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Repaired, self::Compliant => 'success',
            self::Partial => 'warning',
            self::Irreparable => 'danger',
        };
    }
}
