<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum LoanStatus: string implements HasColor, HasLabel
{
    case Ongoing = 'ongoing';
    case Returned = 'returned';

    public function getLabel(): string
    {
        return match ($this) {
            self::Ongoing => 'En cours',
            self::Returned => 'Rendu',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Ongoing => 'info',
            self::Returned => 'success',
        };
    }
}
