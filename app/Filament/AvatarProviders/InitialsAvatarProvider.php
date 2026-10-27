<?php

namespace App\Filament\AvatarProviders;

use Filament\AvatarProviders\Contracts\AvatarProvider;
use Filament\Facades\Filament;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;

/**
 * Avatar à initiales généré localement en SVG : remplace le fournisseur
 * par défaut de Filament, qui charge l'image depuis un service en ligne.
 */
class InitialsAvatarProvider implements AvatarProvider
{
    public function get(Model|Authenticatable $record): string
    {
        $initials = str(Filament::getNameForDefaultAvatar($record))
            ->trim()
            ->explode(' ')
            ->filter()
            ->take(2)
            ->map(fn (string $segment): string => mb_strtoupper(mb_substr($segment, 0, 1)))
            ->join('');

        $svg = '<svg xmlns="http://www.w3.org/2000/svg" width="64" height="64" viewBox="0 0 64 64">'
            .'<rect width="64" height="64" fill="#0B5D6B"/>'
            .'<text x="50%" y="50%" dy=".35em" text-anchor="middle" fill="#FFFFFF" '
            .'font-family="Archivo, sans-serif" font-size="26" font-weight="700">'
            .e($initials)
            .'</text></svg>';

        return 'data:image/svg+xml;base64,'.base64_encode($svg);
    }
}
