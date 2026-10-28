<?php

namespace App\Filament\Resources\Users;

use App\Actions\Users\ResetUserPassword;
use App\Actions\Users\SetUserActive;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;

/**
 * Actions sur un compte (RG-84, RG-85), partagées entre la liste et la fiche.
 */
final class UserActions
{
    public static function resetPassword(): Action
    {
        return Action::make('resetPassword')
            ->label('Réinitialiser le mot de passe')
            ->icon(Heroicon::OutlinedKey)
            ->requiresConfirmation()
            ->modalDescription('Un mot de passe provisoire va être généré. Le compte est débloqué et l\'utilisateur devra choisir un nouveau mot de passe à sa prochaine connexion.')
            ->authorize('resetPassword')
            ->action(function (User $record): void {
                self::notifyTemporaryPassword($record, app(ResetUserPassword::class)($record));
            });
    }

    public static function deactivate(): Action
    {
        return Action::make('deactivate')
            ->label('Désactiver')
            ->icon(Heroicon::OutlinedNoSymbol)
            ->color('danger')
            ->requiresConfirmation()
            ->modalDescription('Le compte ne pourra plus se connecter. Son historique est conservé et il pourra être réactivé.')
            ->visible(fn (User $record): bool => $record->is_active)
            ->authorize('deactivate')
            ->action(fn (User $record) => app(SetUserActive::class)($record, false))
            ->successNotificationTitle('Compte désactivé');
    }

    public static function reactivate(): Action
    {
        return Action::make('reactivate')
            ->label('Réactiver')
            ->icon(Heroicon::OutlinedArrowPath)
            ->color('success')
            ->visible(fn (User $record): bool => ! $record->is_active)
            ->authorize('update')
            ->action(fn (User $record) => app(SetUserActive::class)($record, true))
            ->successNotificationTitle('Compte réactivé');
    }

    public static function notifyTemporaryPassword(User $user, string $password): void
    {
        Notification::make()
            ->title('Mot de passe provisoire de '.$user->username)
            ->body('Mot de passe : '.$password.' — à communiquer en main propre. Il ne sera plus affiché.')
            ->success()
            ->persistent()
            ->send();
    }
}
