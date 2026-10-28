<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum Depreciability: string implements HasColor, HasLabel
{
    case Yes = 'yes';
    case ThresholdBased = 'threshold';
    case No = 'no';

    public function getLabel(): string
    {
        return match ($this) {
            self::Yes => 'Oui',
            self::ThresholdBased => 'Selon seuil',
            self::No => 'Non',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Yes => 'success',
            self::ThresholdBased => 'warning',
            self::No => 'gray',
        };
    }
}
