<?php

namespace App\Filament\Resources\Acquisitions\Schemas;

use App\Filament\Resources\Assets\AssetResource;
use App\Models\Acquisition;
use App\Models\Asset;
use App\Support\Money;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\RepeatableEntry\TableColumn;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

/**
 * E11 : consultation d'une acquisition, lignes et fiches créées.
 */
class AcquisitionInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Facture et affectation')
                ->columns(3)
                ->schema([
                    TextEntry::make('reference')->label('Référence')->fontFamily('mono')->weight('semibold'),
                    TextEntry::make('status')->label('Statut')->badge(),
                    TextEntry::make('acquired_on')->label('Date d\'acquisition')->date('d/m/Y'),
                    TextEntry::make('supplier.name')->label('Fournisseur')->placeholder('—'),
                    TextEntry::make('invoice_reference')->label('Référence de facture')->fontFamily('mono')->placeholder('—'),
                    TextEntry::make('funding_source')->label('Mode de financement')->placeholder('—'),
                    TextEntry::make('site.name')->label('Site'),
                    TextEntry::make('department.name')->label('Département bénéficiaire'),
                    TextEntry::make('total_amount')
                        ->label('Montant total (FCFA)')
                        ->formatStateUsing(fn (int $state): string => Money::format($state))
                        ->fontFamily('mono')
                        ->weight('semibold'),
                    TextEntry::make('validated_at')
                        ->label('Validée le')
                        ->dateTime('d/m/Y H:i')
                        ->visible(fn (Acquisition $record): bool => $record->isValidated()),
                    TextEntry::make('validator.username')
                        ->label('Validée par')
                        ->visible(fn (Acquisition $record): bool => $record->isValidated()),
                ]),
            Section::make('Lignes de la facture')
                ->schema([
                    RepeatableEntry::make('lines')
                        ->hiddenLabel()
                        ->table([
                            TableColumn::make('Désignation'),
                            TableColumn::make('Catégorie'),
                            TableColumn::make('Qté'),
                            TableColumn::make('Prix unitaire (FCFA)'),
                            TableColumn::make('Total (FCFA)'),
                            TableColumn::make('Suivi'),
                        ])
                        ->schema([
                            TextEntry::make('designation'),
                            TextEntry::make('category.name'),
                            TextEntry::make('quantity')->fontFamily('mono'),
                            TextEntry::make('unit_price')->formatStateUsing(fn (int $state): string => Money::format($state))->fontFamily('mono'),
                            TextEntry::make('line_total')->formatStateUsing(fn (int $state): string => Money::format($state))->fontFamily('mono'),
                            TextEntry::make('tracked_individually')
                                ->badge()
                                ->formatStateUsing(fn (bool $state): string => $state ? 'Une fiche par unité' : 'Non suivi')
                                ->color(fn (bool $state): string => $state ? 'primary' : 'gray'),
                        ]),
                ]),
            Section::make('Fiches créées')
                ->visible(fn (Acquisition $record): bool => $record->isValidated())
                ->schema([
                    TextEntry::make('assets.number')
                        ->hiddenLabel()
                        ->badge()
                        ->color('gray')
                        ->fontFamily('mono')
                        ->url(fn (string $state): ?string => ($asset = Asset::query()->where('number', $state)->first()) ? AssetResource::getUrl('view', ['record' => $asset]) : null)
                        ->placeholder('Aucune fiche : toutes les lignes sont des consommables non suivis.'),
                ]),
        ]);
    }
}
