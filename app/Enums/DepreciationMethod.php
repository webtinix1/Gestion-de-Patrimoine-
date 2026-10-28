<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum DepreciationMethod: string implements HasColor, HasLabel
{
    case Linear = 'linear';
    case Declining = 'declining';

    public function getLabel(): string
    {
        return match ($this) {
            self::Linear => 'Linéaire',
            self::Declining => 'Dégressive',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Linear => 'primary',
            self::Declining => 'info',
        };
    }
}
