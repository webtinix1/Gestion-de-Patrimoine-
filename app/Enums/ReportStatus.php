<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

/** RG-64 : parcours d'un signalement. */
enum ReportStatus: string implements HasColor, HasLabel
{
    case New = 'new';
    case InProgress = 'in_progress';
    case Handled = 'handled';
    case Rejected = 'rejected';

    public function getLabel(): string
    {
        return match ($this) {
            self::New => 'Nouveau',
            self::InProgress => 'Pris en charge',
            self::Handled => 'Traité',
            self::Rejected => 'Rejeté',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::New => 'primary',
            self::InProgress => 'info',
            self::Handled => 'success',
            self::Rejected => 'gray',
        };
    }

    public function isOpen(): bool
    {
        return $this === self::New || $this === self::InProgress;
    }
}
