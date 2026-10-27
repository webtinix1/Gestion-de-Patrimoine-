<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum AcquisitionStatus: string implements HasColor, HasLabel
{
    case Draft = 'draft';
    case Validated = 'validated';

    public function getLabel(): string
    {
        return match ($this) {
            self::Draft => 'Brouillon',
            self::Validated => 'Validée',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Draft => 'warning',
            self::Validated => 'success',
        };
    }
}
