<?php

namespace App\Filament\Resources\Assets\Schemas;

use App\Models\Activity;
use App\Models\Asset;
use App\Models\User;
use App\Support\Money;
use Filament\Infolists\Components\ImageEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Callout;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Schema;
use Illuminate\Support\HtmlString;

/**
 * E06 : fiche d'un bien. E43 : bandeau quand le bien est sorti du patrimoine.
 * Les onglets Mouvements, Amortissement, Maintenance et Prêts arrivent avec leurs lots.
 */
class AssetInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(1)
            ->components([
                Callout::make('Sorti du patrimoine')
                    ->description(fn (Asset $record): string => 'Ce bien est sorti du patrimoine le '.$record->disposed_at?->format('d/m/Y').'. Sa fiche est consultable en lecture seule.')
                    ->danger()
                    ->visible(fn (Asset $record): bool => $record->isDisposed()),
                Section::make()
                    ->columns(4)
                    ->schema([
                        // RG-66 : photo servie par une route contrôlée, pas par une URL signée.
                        ImageEntry::make('photo_path')
                            ->hiddenLabel()
                            ->state(fn (Asset $record): ?string => $record->photo_path
                                ? route('filament.admin.assets.photo', ['asset' => $record->getKey()])
                                : null)
                            ->imageHeight(120)
                            ->placeholder('Pas de photo'),
                        Grid::make(1)
                            ->columnSpan(3)
                            ->schema([
                                TextEntry::make('number')
                                    ->hiddenLabel()
                                    ->fontFamily('mono')
                                    ->weight('bold')
                                    ->size('lg')
                                    ->color('primary')
                                    ->copyable(),
                                TextEntry::make('designation')
                                    ->hiddenLabel()
                                    ->state(fn (Asset $record): string => $record->label())
                                    ->weight('bold')
                                    ->size('lg'),
                                Grid::make(3)->schema([
                                    TextEntry::make('status')->label('Statut')->badge(),
                                    TextEntry::make('condition.label')
                                        ->label('État')
                                        ->badge()
                                        ->color(fn (Asset $record): string => $record->condition->color),
                                    TextEntry::make('acquisition_cost')
                                        ->label('Valeur d\'origine')
                                        ->formatStateUsing(fn (?int $state): string => Money::format($state, withCurrency: true))
                                        ->fontFamily('mono'),
                                ]),
                            ]),
                    ]),
                Tabs::make('Fiche')
                    ->persistTabInQueryString()
                    ->tabs([
                        Tab::make('Informations')->schema([
                            Section::make('Identification')->columns(3)->schema([
                                TextEntry::make('category.name')->label('Catégorie'),
                                TextEntry::make('brand')->label('Marque')->placeholder('—'),
                                TextEntry::make('model')->label('Modèle')->placeholder('—'),
                                TextEntry::make('serial_number')->label('Numéro de série')->fontFamily('mono')->placeholder('—'),
                                TextEntry::make('description')->label('Description')->placeholder('—')->columnSpan(2),
                            ]),
                            Section::make('Acquisition')->columns(3)->schema([
                                TextEntry::make('acquired_on')->label('Date d\'acquisition')->date(),
                                TextEntry::make('in_service_on')->label('Mise en service')->date()->placeholder('—'),
                                TextEntry::make('supplier.name')->label('Fournisseur')->placeholder('—'),
                                TextEntry::make('invoice_reference')->label('Facture')->placeholder('—'),
                                TextEntry::make('funding_source')->label('Financement')->placeholder('—'),
                            ]),
                            Section::make('Affectation')->columns(3)->schema([
                                TextEntry::make('site.name')->label('Site'),
                                TextEntry::make('department.name')->label('Département'),
                                TextEntry::make('service.name')->label('Service')->placeholder('—'),
                                TextEntry::make('room.name')->label('Local')->placeholder('—'),
                                TextEntry::make('holder.name')->label('Responsable')->placeholder('—'),
                                TextEntry::make('assigned_since')->label('Affecté depuis')->date()->placeholder('—'),
                            ]),
                            Section::make('Immobilisation')->columns(3)->schema([
                                TextEntry::make('is_depreciable')
                                    ->label('Amortissable')
                                    ->formatStateUsing(fn (bool $state): string => $state ? 'Oui' : 'Non')
                                    ->badge()
                                    ->color(fn (bool $state): string => $state ? 'success' : 'gray'),
                                TextEntry::make('depreciation_method')->label('Méthode')->placeholder('—'),
                                TextEntry::make('duration_years')->label('Durée')->suffix(' ans')->placeholder('—'),
                                TextEntry::make('residual_value')
                                    ->label('Valeur résiduelle')
                                    ->formatStateUsing(fn (?int $state): string => Money::format($state, withCurrency: true)),
                                TextEntry::make('asset_account')->label('Compte d\'immobilisation')->fontFamily('mono')->placeholder('—'),
                                TextEntry::make('depreciation_account')->label('Compte d\'amortissement')->fontFamily('mono')->placeholder('—'),
                            ]),
                            Section::make('Observations')->schema([
                                TextEntry::make('notes')->hiddenLabel()->placeholder('Aucune observation.'),
                            ]),
                        ]),
                        Tab::make('Journal')->schema([
                            Text::make(fn (Asset $record): HtmlString => self::history($record)),
                        ]),
                    ]),
            ]);
    }

    private static function history(Asset $asset): HtmlString
    {
        $activities = Activity::query()
            ->where('subject_type', $asset->getMorphClass())
            ->where('subject_id', $asset->getKey())
            ->with('causer')
            ->latest('id')
            ->limit(30)
            ->get();

        if ($activities->isEmpty()) {
            return new HtmlString('<span class="text-gray-500">Aucune opération enregistrée.</span>');
        }

        return new HtmlString('<ul class="space-y-2">'.$activities->map(function (Activity $activity): string {
            $causer = $activity->causer;
            $who = $causer instanceof User ? $causer->fullName() : 'Système';

            return '<li><span class="pat-mono">'.e($activity->created_at?->format('d/m/Y H:i')).'</span> · '
                .e($who).' · <strong>'.e($activity->description).'</strong></li>';
        })->join('').'</ul>');
    }
}
