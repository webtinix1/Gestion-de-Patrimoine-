<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

/** RG-55 : bénéficiaire interne (département) ou externe (personne, organisation). */
enum BorrowerType: string implements HasLabel
{
    case Internal = 'internal';
    case External = 'external';

    public function getLabel(): string
    {
        return match ($this) {
            self::Internal => 'Interne (département)',
            self::External => 'Externe (personne ou organisation)',
        };
    }
}
