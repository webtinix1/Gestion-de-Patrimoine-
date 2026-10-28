<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

/** RG-55, RG-58 : prêt par l'Église (out) ou emprunt d'un bien de tiers (in). */
enum LoanDirection: string implements HasColor, HasLabel
{
    case Out = 'out';
    case In = 'in';

    public function getLabel(): string
    {
        return match ($this) {
            self::Out => 'Prêt',
            self::In => 'Emprunt',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Out => 'primary',
            self::In => 'info',
        };
    }
}
