<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

/** RG-21, RG-22 : nature d'un mouvement, déduite des éléments d'affectation modifiés. */
enum MovementType: string implements HasColor, HasLabel
{
    case Initial = 'initial';
    case Department = 'department';
    case Service = 'service';
    case Room = 'room';
    case Site = 'site';
    case Holder = 'holder';
    case Mixed = 'mixed';

    public function getLabel(): string
    {
        return match ($this) {
            self::Initial => 'Affectation initiale',
            self::Department => 'Changement de département',
            self::Service => 'Changement de service',
            self::Room => 'Changement de local',
            self::Site => 'Changement de site',
            self::Holder => 'Changement de responsable',
            self::Mixed => 'Transfert multiple',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Initial => 'gray',
            self::Department, self::Site => 'primary',
            self::Service, self::Room => 'info',
            self::Holder => 'warning',
            self::Mixed => 'success',
        };
    }
}
