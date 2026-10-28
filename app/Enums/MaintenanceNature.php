<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum MaintenanceNature: string implements HasLabel
{
    case Repair = 'repair';
    case Preventive = 'preventive';
    case Diagnostic = 'diagnostic';
    case PartReplacement = 'part_replacement';

    public function getLabel(): string
    {
        return match ($this) {
            self::Repair => 'Réparation',
            self::Preventive => 'Entretien préventif',
            self::Diagnostic => 'Diagnostic',
            self::PartReplacement => 'Remplacement de pièce',
        };
    }
}
