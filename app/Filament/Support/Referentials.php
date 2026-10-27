<?php

namespace App\Filament\Support;

use App\Actions\Referentials\SetReferentialActive;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Forms\Components\Toggle;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Illuminate\Database\Eloquent\Model;

/**
 * Éléments d'interface communs aux référentiels (Structure, Catégories et référentiels).
 */
final class Referentials
{
    public static function activeToggle(string $label = 'Actif'): Toggle
    {
        return Toggle::make('is_active')
            ->label($label)
            ->helperText('Un élément inactif reste dans l\'historique mais n\'est plus proposé pour les nouvelles saisies.')
            ->default(true);
    }

    public static function activeColumn(): TextColumn
    {
        return TextColumn::make('is_active')
            ->label('Statut')
            ->badge()
            ->formatStateUsing(fn (bool $state): string => $state ? 'Actif' : 'Inactif')
            ->color(fn (bool $state): string => $state ? 'success' : 'gray');
    }

    public static function activeFilter(): TernaryFilter
    {
        return TernaryFilter::make('is_active')
            ->label('Statut')
            ->placeholder('Tous')
            ->trueLabel('Actifs')
            ->falseLabel('Inactifs');
    }

    public static function deactivateAction(): Action
    {
        return Action::make('deactivate')
            ->label('Désactiver')
            ->icon(Heroicon::OutlinedNoSymbol)
            ->color('gray')
            ->requiresConfirmation()
            ->modalDescription('L\'élément ne sera plus proposé pour les nouvelles saisies. Vous pourrez le réactiver.')
            ->visible(fn (Model $record): bool => (bool) $record->getAttribute('is_active'))
            ->authorize('update')
            ->action(fn (Model $record) => app(SetReferentialActive::class)($record, false))
            ->successNotificationTitle('Élément désactivé');
    }

    public static function reactivateAction(): Action
    {
        return Action::make('reactivate')
            ->label('Réactiver')
            ->icon(Heroicon::OutlinedArrowPath)
            ->color('success')
            ->visible(fn (Model $record): bool => ! $record->getAttribute('is_active'))
            ->authorize('update')
            ->action(fn (Model $record) => app(SetReferentialActive::class)($record, true))
            ->successNotificationTitle('Élément réactivé');
    }

    /**
     * Visible seulement si l'élément n'est pas utilisé (policy « delete »).
     */
    public static function deleteAction(): DeleteAction
    {
        return DeleteAction::make()
            ->modalDescription('Cet élément n\'est utilisé nulle part : il sera supprimé définitivement.');
    }

    /**
     * @return list<Action>
     */
    public static function recordActions(): array
    {
        return [
            self::deactivateAction(),
            self::reactivateAction(),
            self::deleteAction(),
        ];
    }
}
