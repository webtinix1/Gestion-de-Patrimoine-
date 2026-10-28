<?php

namespace App\Filament\Resources\Assets\RelationManagers;

use App\Filament\Resources\Maintenance\MaintenanceResource;
use App\Models\MaintenanceJob;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * E06 : onglet « Maintenance » d'un bien, en lecture seule (RG-60 à RG-62).
 */
class MaintenanceJobsRelationManager extends RelationManager
{
    protected static string $relationship = 'maintenanceJobs';

    protected static ?string $title = 'Maintenance';

    protected static ?string $modelLabel = 'intervention';

    protected static ?string $pluralModelLabel = 'interventions';

    public function isReadOnly(): bool
    {
        return true;
    }

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return auth()->user()?->can('viewAny', MaintenanceJob::class) ?? false;
    }

    public function table(Table $table): Table
    {
        return $table
            ->defaultSort('starts_on', 'desc')
            ->emptyStateHeading('Aucune intervention')
            ->emptyStateDescription('Ce bien n\'a pas encore fait l\'objet d\'une intervention.')
            ->columns([
                TextColumn::make('reference')
                    ->label('N°')
                    ->fontFamily('mono')
                    ->weight('semibold')
                    ->color('primary')
                    ->url(fn (MaintenanceJob $record): string => MaintenanceResource::getUrl('view', ['record' => $record])),
                TextColumn::make('nature')->label('Nature')->description(fn (MaintenanceJob $record): string => $record->reason)->wrap(),
                TextColumn::make('starts_on')->label('Début')->date('d/m/Y'),
                TextColumn::make('ends_on')->label('Fin')->date('d/m/Y')->placeholder('—'),
                TextColumn::make('provider_name')->label('Prestataire')->placeholder('—'),
                TextColumn::make('cost')->label('Coût')->numeric(thousandsSeparator: ' ')->fontFamily('mono')->alignEnd()->placeholder('—'),
                TextColumn::make('status')->label('Statut')->badge(),
                TextColumn::make('result')->label('Résultat')->badge()->placeholder('—'),
            ]);
    }
}
