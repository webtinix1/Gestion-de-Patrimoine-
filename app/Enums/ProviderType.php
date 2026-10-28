<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

/** RG-60 : intervenant interne ou prestataire externe. */
enum ProviderType: string implements HasLabel
{
    case Internal = 'internal';
    case Provider = 'provider';

    public function getLabel(): string
    {
        return match ($this) {
            self::Internal => 'Interne',
            self::Provider => 'Prestataire externe',
        };
    }
}
