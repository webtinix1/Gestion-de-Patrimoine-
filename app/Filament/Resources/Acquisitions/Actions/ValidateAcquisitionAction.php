<?php

namespace App\Filament\Resources\Acquisitions\Actions;

use App\Actions\Acquisitions\ValidateAcquisition;
use App\Filament\Resources\Acquisitions\AcquisitionResource;
use App\Models\Acquisition;
use App\Models\Asset;
use App\Support\LabelsUrl;
use DomainException;
use Filament\Actions\Action;
use Filament\Forms\Components\Checkbox;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Livewire\Component;

/**
 * E12 : « Valider et créer les fiches » (RG-15), puis enchaînement vers l'impression des étiquettes (RG-16).
 */
final class ValidateAcquisitionAction
{
    public static function make(): Action
    {
        return Action::make('validate')
            ->label('Valider et créer les fiches')
            ->icon(Heroicon::OutlinedCheckBadge)
            ->color('primary')
            ->visible(fn (Acquisition $record): bool => auth()->user()?->can('validate', $record) ?? false)
            ->modalHeading('Valider l\'acquisition ?')
            ->modalDescription(fn (Acquisition $record): string => sprintf(
                '%s : %d fiche(s) seront créées, une par unité des lignes à suivi individuel. L\'acquisition ne sera plus modifiable.',
                $record->reference,
                $record->lines()->where('tracked_individually', true)->sum('quantity'),
            ))
            ->modalSubmitActionLabel('Valider et créer les fiches')
            ->schema(fn (Acquisition $record): array => $record->documents()->exists()
                ? [Checkbox::make('attach_to_assets')->label('Rattacher aussi aux biens créés')->helperText('Les justificatifs seront aussi visibles sur chaque fiche.')]
                : [])
            ->action(function (Acquisition $record, array $data, Component $livewire): void {
                abort_unless(auth()->user()?->can('validate', $record), 403);

                try {
                    $assets = app(ValidateAcquisition::class)($record, (bool) ($data['attach_to_assets'] ?? false));
                } catch (DomainException $exception) {
                    Notification::make()->danger()->title($exception->getMessage())->send();

                    return;
                }

                Notification::make()->success()
                    ->title($assets->isEmpty()
                        ? 'Acquisition validée, aucune fiche à créer'
                        : $assets->count().' fiche(s) créée(s) : '.$assets->first()->number.($assets->count() > 1 ? ' à '.$assets->last()->number : ''))
                    ->send();

                // RG-16 : proposition d'imprimer les étiquettes des biens créés.
                $livewire->redirect($assets->isEmpty()
                    ? AcquisitionResource::getUrl('view', ['record' => $record])
                    : LabelsUrl::for($assets->map(fn (Asset $asset): int => $asset->getKey())));
            });
    }
}
