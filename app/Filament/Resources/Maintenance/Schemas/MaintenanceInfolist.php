<?php

namespace App\Filament\Resources\Maintenance\Schemas;

use App\Filament\Resources\Assets\AssetResource;
use App\Models\MaintenanceJob;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

/**
 * E22, E23 : fiche d'une intervention.
 */
class MaintenanceInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Intervention')
                ->columns(3)
                ->columnSpanFull()
                ->schema([
                    TextEntry::make('reference')->label('Référence')->fontFamily('mono')->weight('semibold'),
                    TextEntry::make('status')->label('Statut')->badge(),
                    TextEntry::make('result')->label('Résultat')->badge()->placeholder('—'),
                    TextEntry::make('asset.number')
                        ->label('Bien')
                        ->fontFamily('mono')
                        ->helperText(fn (MaintenanceJob $record): ?string => $record->asset?->label())
                        ->url(fn (MaintenanceJob $record): string => AssetResource::getUrl('view', ['record' => $record->asset_id])),
                    TextEntry::make('nature')->label('Nature'),
                    TextEntry::make('reason')->label('Motif'),
                    TextEntry::make('starts_on')->label('Début')->date('d/m/Y'),
                    TextEntry::make('ends_on')->label('Fin')->date('d/m/Y')->placeholder('—'),
                    TextEntry::make('provider_type')
                        ->label('Intervenant')
                        ->helperText(fn (MaintenanceJob $record): ?string => $record->provider_name),
                    TextEntry::make('cost')->label('Coût')->numeric(thousandsSeparator: ' ')->suffix(' FCFA')->placeholder('—')
                        ->helperText(fn (MaintenanceJob $record): ?string => $record->cost_reference),
                    TextEntry::make('conditionAfter.label')->label('Nouvel état du bien')->placeholder('—'),
                    TextEntry::make('status_after')->label('Statut après intervention')->placeholder('—'),
                    TextEntry::make('notes')->label('Observations')->placeholder('—')->columnSpanFull(),
                ]),
        ]);
    }
}
