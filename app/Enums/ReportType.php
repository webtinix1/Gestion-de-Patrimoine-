<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

/** RG-63 : ce qu'un responsable de département peut signaler. */
enum ReportType: string implements HasColor, HasLabel
{
    case Condition = 'condition';
    case Move = 'move';
    case Maintenance = 'maintenance';

    public function getLabel(): string
    {
        return match ($this) {
            self::Condition => 'Changement d\'état',
            self::Move => 'Déplacement',
            self::Maintenance => 'Besoin de maintenance',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Condition => 'danger',
            self::Move => 'info',
            self::Maintenance => 'warning',
        };
    }
}
