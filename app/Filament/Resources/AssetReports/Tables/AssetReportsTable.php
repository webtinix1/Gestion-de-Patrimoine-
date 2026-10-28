<?php

namespace App\Filament\Resources\AssetReports\Tables;

use App\Enums\ReportStatus;
use App\Enums\ReportType;
use App\Filament\Actions\AssetReportActions;
use App\Filament\Resources\AssetReports\AssetReportResource;
use App\Models\AssetReport;
use Filament\Actions\ActionGroup;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * E24 : liste des signalements.
 */
class AssetReportsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('id', 'desc')
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['asset', 'creator']))
            ->emptyStateHeading('Aucun signalement')
            ->emptyStateDescription('Les changements d\'état, déplacements et besoins de maintenance remontés par les responsables apparaîtront ici.')
            ->columns([
                TextColumn::make('reference')
                    ->label('N°')
                    ->fontFamily('mono')
                    ->weight('semibold')
                    ->color('primary')
                    ->searchable()
                    ->sortable()
                    ->url(fn (AssetReport $record): string => AssetReportResource::getUrl('view', ['record' => $record])),
                TextColumn::make('type')->label('Nature')->badge(),
                TextColumn::make('status')->label('Statut')->badge(),
                TextColumn::make('asset.number')
                    ->label('Bien')
                    ->fontFamily('mono')
                    ->description(fn (AssetReport $record): ?string => $record->asset?->label())
                    ->searchable(query: fn (Builder $query, string $search): Builder => $query->whereHas(
                        'asset',
                        fn (Builder $asset) => $asset->where('number', 'like', "%{$search}%")->orWhere('designation', 'like', "%{$search}%"),
                    )),
                TextColumn::make('description')->label('Description')->limit(80)->wrap()->searchable(),
                TextColumn::make('urgency')->label('Urgence')->badge(),
                TextColumn::make('creator')
                    ->label('Signalé par')
                    ->state(fn (AssetReport $record): ?string => $record->creator?->fullName())
                    ->description(fn (AssetReport $record): ?string => $record->created_at?->format('d/m/Y')),
            ])
            ->filters([
                SelectFilter::make('type')->label('Nature')->options(ReportType::class),
                SelectFilter::make('status')->label('Statut')->options(ReportStatus::class),
            ])
            ->recordActions([
                ViewAction::make()->label('Détail'),
                ActionGroup::make(AssetReportActions::processing())->label('Traiter'),
            ]);
    }
}
