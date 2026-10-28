<?php

namespace App\Filament\Actions;

use App\Enums\AssetStatus;
use App\Filament\Resources\Disposals\DisposalResource;
use App\Models\Asset;
use App\Models\Disposal;
use Filament\Actions\Action;
use Filament\Support\Icons\Heroicon;

/**
 * E06 : action « Sortir » de la fiche d'un bien, vers l'écran de sortie E26 prérempli (RG-50).
 */
final class DisposeAction
{
    public static function make(Asset $asset): Action
    {
        $blocked = in_array($asset->status, [AssetStatus::OnLoan, AssetStatus::InMaintenance], true);

        return Action::make('dispose')
            ->label('Sortir')
            ->icon(Heroicon::OutlinedArchiveBoxXMark)
            ->color('danger')
            ->visible(fn (): bool => auth()->user()?->can('dispose', [Disposal::class, $asset]) ?? false)
            ->disabled($blocked)
            ->tooltip($blocked ? 'Clôturez d\'abord le prêt ou l\'intervention en cours.' : null)
            ->url($blocked ? null : DisposalResource::getUrl('create', ['bien' => $asset->getKey()]));
    }
}
