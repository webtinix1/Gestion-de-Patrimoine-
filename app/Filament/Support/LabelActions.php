<?php

namespace App\Filament\Support;

use App\Actions\Labels\PrintLabels;
use App\Filament\Pages\LabelPrinting;
use App\Models\Asset;
use App\Models\LabelTemplate;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Notifications\Notification;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Gate;

/**
 * RG-97 : entrées vers l'impression des étiquettes depuis la liste des biens (en lot) et la fiche (unitaire).
 */
final class LabelActions
{
    public static function bulk(): BulkAction
    {
        return BulkAction::make('printLabels')
            ->label('Imprimer les étiquettes')
            ->icon('heroicon-o-printer')
            ->color('gray')
            ->deselectRecordsAfterCompletion()
            ->visible(fn (): bool => Gate::allows('print', LabelTemplate::class))
            ->action(function (Collection $records) {
                if ($records->count() > PrintLabels::MAX_LABELS) {
                    Notification::make()->danger()->title('Trop de biens sélectionnés (maximum '.PrintLabels::MAX_LABELS.').')->send();

                    return null;
                }

                return redirect(LabelPrinting::getUrl(['biens' => $records->pluck('id')->implode(',')]));
            });
    }

    public static function single(): Action
    {
        return Action::make('printLabel')
            ->label('Imprimer l\'étiquette')
            ->icon('heroicon-o-printer')
            ->color('gray')
            ->visible(fn (Asset $record): bool => Gate::allows('print', LabelTemplate::class) && ! $record->isDisposed())
            ->url(fn (Asset $record): string => LabelPrinting::getUrl(['biens' => $record->id]));
    }
}
