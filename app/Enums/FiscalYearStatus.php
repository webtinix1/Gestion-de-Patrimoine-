<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

/**
 * RG-36, RG-43 : un exercice est préparé, ouvert ou clôturé ; la clôture est définitive.
 */
enum FiscalYearStatus: string implements HasColor, HasLabel
{
    case Prepared = 'prepared';
    case Open = 'open';
    case Closed = 'closed';

    public function getLabel(): string
    {
        return match ($this) {
            self::Prepared => 'Préparé',
            self::Open => 'Ouvert',
            self::Closed => 'Clôturé',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Prepared => 'gray',
            self::Open => 'info',
            self::Closed => 'success',
        };
    }
}
