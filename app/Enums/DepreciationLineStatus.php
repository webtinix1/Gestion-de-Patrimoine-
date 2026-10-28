<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

/**
 * Statut affiché d'une ligne de plan (E31), déduit de l'exercice.
 */
enum DepreciationLineStatus: string implements HasColor, HasLabel
{
    case Prior = 'prior';
    case Closed = 'closed';
    case Open = 'open';
    case Prepared = 'prepared';
    case Forecast = 'forecast';

    public function getLabel(): string
    {
        return match ($this) {
            self::Prior => 'Antérieur',
            self::Closed => 'Clôturé',
            self::Open => 'En cours',
            self::Prepared => 'Préparé',
            self::Forecast => 'Prévisionnel',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Prior, self::Prepared, self::Forecast => 'gray',
            self::Closed => 'success',
            self::Open => 'info',
        };
    }
}
