<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

/** RG-54 : traitement comptable d'une sortie, « à valider » jusqu'à la validation du comptable. */
enum DisposalAccountingStatus: string implements HasColor, HasLabel
{
    case Pending = 'pending';
    case Validated = 'validated';

    public function getLabel(): string
    {
        return match ($this) {
            self::Pending => 'À valider',
            self::Validated => 'Validée',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Pending => 'warning',
            self::Validated => 'success',
        };
    }
}
