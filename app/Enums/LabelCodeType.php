<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

/**
 * RG-95 : QR code ou code-barres Code 128, tous deux limités au numéro patrimonial.
 */
enum LabelCodeType: string implements HasLabel
{
    case Qr = 'qr';
    case Code128 = 'code128';

    public function getLabel(): string
    {
        return match ($this) {
            self::Qr => 'QR code',
            self::Code128 => 'Code-barres Code 128',
        };
    }
}
