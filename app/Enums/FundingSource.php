<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum FundingSource: string implements HasColor, HasLabel
{
    case OwnFunds = 'own_funds';
    case EarmarkedDonation = 'earmarked_donation';
    case Grant = 'grant';

    public function getLabel(): string
    {
        return match ($this) {
            self::OwnFunds => 'Fonds propres',
            self::EarmarkedDonation => 'Don affecté',
            self::Grant => 'Subvention',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::OwnFunds => 'gray',
            self::EarmarkedDonation => 'gray',
            self::Grant => 'gray',
        };
    }
}
