<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum ReportUrgency: string implements HasColor, HasLabel
{
    case Normal = 'normal';
    case Urgent = 'urgent';

    public function getLabel(): string
    {
        return match ($this) {
            self::Normal => 'Normale',
            self::Urgent => 'Urgente (bloque une activité)',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Normal => 'gray',
            self::Urgent => 'danger',
        };
    }
}
