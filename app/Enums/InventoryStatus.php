<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

/** RG-44 à RG-48 : planifié, en cours (liste figée), à valider (saisie terminée), clôturé (résultats figés). */
enum InventoryStatus: string implements HasColor, HasLabel
{
    case Planned = 'planned';
    case InProgress = 'in_progress';
    case ToValidate = 'to_validate';
    case Closed = 'closed';

    public function getLabel(): string
    {
        return match ($this) {
            self::Planned => 'Planifié',
            self::InProgress => 'En cours',
            self::ToValidate => 'À valider',
            self::Closed => 'Clôturé',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Planned => 'gray',
            self::InProgress => 'info',
            self::ToValidate => 'warning',
            self::Closed => 'success',
        };
    }
}
