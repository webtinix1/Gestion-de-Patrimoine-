<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum MaintenanceStatus: string implements HasColor, HasLabel
{
    case Planned = 'planned';
    case Ongoing = 'ongoing';
    case Done = 'done';

    public function getLabel(): string
    {
        return match ($this) {
            self::Planned => 'Planifiée',
            self::Ongoing => 'En cours',
            self::Done => 'Terminée',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Planned => 'gray',
            self::Ongoing => 'info',
            self::Done => 'success',
        };
    }

    public function isOpen(): bool
    {
        return $this !== self::Done;
    }
}
