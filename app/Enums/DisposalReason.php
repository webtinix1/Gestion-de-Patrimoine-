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
            self::Sale => 'primary',
            self::Donation => 'info',
            self::Retirement, self::Destruction, self::Other => 'gray',
            self::Loss, self::Theft => 'danger',
        };
    }

    // RG-52 : Cédé pour une cession ou un don, Réformé pour les autres motifs.
    public function resultingStatus(): AssetStatus
    {
        return in_array($this, [self::Sale, self::Donation], true) ? AssetStatus::TransferredOut : AssetStatus::Retired;
    }

    // RG-51 : acquéreur et prix pour une cession ; bénéficiaire pour un don.
    public function requiresCounterparty(): bool
    {
        return in_array($this, [self::Sale, self::Donation], true);
    }

    public function requiresPrice(): bool
    {
        return $this === self::Sale;
    }

    public function counterpartyLabel(): string
    {
        return $this === self::Donation ? 'Bénéficiaire du don' : 'Acquéreur';
    }
}
