<?php

namespace App\Filament\Resources\Disposals\Actions;

use App\Actions\Disposals\ValidateDisposal;
use App\Models\Disposal;
use App\Models\User;
use App\Support\Money;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Illuminate\Validation\ValidationException;

/**
 * E27 : validation comptable d'une sortie (RG-54), par l'administrateur ou le comptable (RG-83).
 */
final class ValidateDisposalAction
{
    public static function make(): Action
    {
        return Action::make('validateDisposal')
            ->label('Valider')
            ->icon(Heroicon::OutlinedCheckBadge)
            ->color('primary')
            ->visible(fn (Disposal $record): bool => auth()->user()?->can('validate', $record) ?? false)
            ->requiresConfirmation()
            ->modalHeading(fn (Disposal $record): string => 'Valider le traitement comptable de '.$record->reference.' ?')
            ->modalDescription(fn (Disposal $record): string => sprintf(
                'Valeur d\'origine %s, amortissements cumulés %s, VNC %s à la date de sortie. La validation est définitive et tracée ; aucune écriture comptable n\'est générée.',
                Money::format($record->original_value, withCurrency: true),
                Money::format($record->accumulated_depreciation, withCurrency: true),
                Money::format($record->net_book_value, withCurrency: true),
            ))
            ->modalSubmitActionLabel('Valider la sortie')
            ->action(function (Disposal $record): void {
                $user = auth()->user();
                abort_unless($user instanceof User && $user->can('validate', $record), 403);

                try {
                    app(ValidateDisposal::class)($record, $user);
                } catch (ValidationException $exception) {
                    Notification::make()->danger()->title(collect($exception->errors())->flatten()->first() ?? 'Validation impossible.')->send();

                    return;
                }

                Notification::make()->success()->title('Sortie '.$record->reference.' validée')->send();
            });
    }
}
