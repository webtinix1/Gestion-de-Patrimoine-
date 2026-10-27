<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

/** RG-50 : motifs de sortie du patrimoine. */
enum DisposalReason: string implements HasColor, HasLabel
{
    case Sale = 'sale';
    case Donation = 'donation';
    case Retirement = 'retirement';
    case Destruction = 'destruction';
    case Loss = 'loss';
    case Theft = 'theft';
    case Other = 'other';

    public function getLabel(): string
    {
        return match ($this) {
            self::Sale => 'Cession',
            self::Donation => 'Don',
            self::Retirement => 'Réforme',
            self::Destruction => 'Destruction',
            self::Loss => 'Perte',
            self::Theft => 'Vol',
            self::Other => 'Autre motif',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Sale => 'gray',
            self::Donation => 'gray',
            self::Retirement => 'gray',
            self::Destruction => 'gray',
            self::Loss => 'gray',
            self::Theft => 'gray',
            self::Other => 'gray',
        };
    }
}
