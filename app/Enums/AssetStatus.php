<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

/** RG-11 : statuts fixes ; RG-12 : certains ne sont posés que par une opération. */
enum AssetStatus: string implements HasColor, HasLabel
{
    case InService = 'in_service';
    case InStock = 'in_stock';
    case InMaintenance = 'in_maintenance';
    case OnLoan = 'on_loan';
    case OutOfService = 'out_of_service';
    case TransferredOut = 'transferred_out';
    case Retired = 'retired';

    public function getLabel(): string
    {
        return match ($this) {
            self::InService => 'En service',
            self::InStock => 'En stock',
            self::InMaintenance => 'En maintenance',
            self::OnLoan => 'Emprunté',
            self::OutOfService => 'Hors service',
            self::TransferredOut => 'Cédé',
            self::Retired => 'Réformé',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::InService => 'success',
            self::InStock => 'gray',
            self::InMaintenance => 'info',
            self::OnLoan => 'warning',
            self::OutOfService => 'danger',
            self::TransferredOut => 'gray',
            self::Retired => 'gray',
        };
    }

    // RG-12 : En maintenance, Emprunté, Cédé et Réformé sont posés par une opération.
    public function isManuallySelectable(): bool
    {
        return in_array($this, [self::InService, self::InStock, self::OutOfService], true);
    }

    public function setBy(): string
    {
        return match ($this) {
            self::InService, self::InStock => 'Saisie manuelle',
            self::InMaintenance => 'Intervention de maintenance',
            self::OnLoan => 'Prêt',
            self::OutOfService => 'Saisie, signalement',
            self::TransferredOut, self::Retired => 'Sortie du patrimoine',
        };
    }

    public function isInActivePatrimony(): bool
    {
        return ! in_array($this, [self::TransferredOut, self::Retired], true);
    }
}
