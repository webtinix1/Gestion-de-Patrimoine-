<?php

namespace App\Filament\Resources\Loans\Schemas;

use App\Filament\Resources\Assets\AssetResource;
use App\Models\Loan;
use App\Models\LoanItem;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

/**
 * E15 : fiche d'un prêt ou d'un emprunt, avec la comparaison des états au départ et au retour (RG-57).
 */
class LoanInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Opération')
                ->columns(3)
                ->columnSpanFull()
                ->schema([
                    TextEntry::make('reference')->label('Référence')->fontFamily('mono')->weight('semibold'),
                    TextEntry::make('direction')->label('Type')->badge(),
                    TextEntry::make('situation')
                        ->label('Situation')
                        ->state(fn (Loan $record): string => $record->situationLabel())
                        ->badge()
                        ->color(fn (Loan $record): string => $record->situationColor()),
                    TextEntry::make('borrower_name')
                        ->label(fn (Loan $record): string => $record->direction->value === 'in' ? 'Prêteur' : 'Bénéficiaire')
                        ->helperText(fn (Loan $record): ?string => $record->borrower_contact),
                    TextEntry::make('borrower_type')->label('Type de bénéficiaire'),
                    TextEntry::make('responsible.first_name')
                        ->label('Responsable')
                        ->state(fn (Loan $record): ?string => $record->responsible?->fullName())
                        ->placeholder('—'),
                    TextEntry::make('out_on')->label('Sortie')->date('d/m/Y'),
                    TextEntry::make('due_on')->label('Retour prévu')->date('d/m/Y'),
                    TextEntry::make('returned_on')->label('Retour effectif')->date('d/m/Y')->placeholder('—'),
                    TextEntry::make('notes')->label('Observations')->placeholder('—')->columnSpanFull(),
                ]),
            Section::make('Biens')
                ->columnSpanFull()
                ->schema([
                    RepeatableEntry::make('items')
                        ->hiddenLabel()
                        ->columns(4)
                        ->schema([
                            TextEntry::make('label')
                                ->label('Bien')
                                ->state(fn (LoanItem $record): string => $record->label())
                                ->url(fn (LoanItem $record): ?string => $record->asset_id !== null && $record->asset !== null ? AssetResource::getUrl('view', ['record' => $record->asset_id]) : null)
                                ->weight('semibold'),
                            TextEntry::make('conditionOut.label')->label('État au départ'),
                            TextEntry::make('conditionBack.label')
                                ->label('État au retour')
                                ->placeholder('—')
                                ->badge()
                                ->color(fn (LoanItem $record): string => $record->isDegraded() ? 'warning' : 'success'),
                            TextEntry::make('previous_status')->label('Statut avant le prêt')->placeholder('—'),
                            TextEntry::make('external_value')->label('Valeur estimée')->numeric(thousandsSeparator: ' ')->suffix(' FCFA')->placeholder('—')->visible(fn (LoanItem $record): bool => $record->asset_id === null),
                            TextEntry::make('return_notes')->label('Constat au retour')->placeholder('—')->columnSpan(3),
                        ]),
                ]),
        ]);
    }
}
