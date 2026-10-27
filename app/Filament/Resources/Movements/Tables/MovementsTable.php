<?php

namespace App\Filament\Resources\Movements\Tables;

use App\Enums\MovementType;
use App\Filament\Resources\Assets\AssetResource;
use App\Models\Asset;
use App\Models\Department;
use App\Models\Movement;
use App\Models\Site;
use App\Models\User;
use Filament\Forms\Components\DatePicker;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * E13 : liste des mouvements, en lecture seule. Partagée avec l'onglet « Affectation et mouvements » de la fiche (E06).
 */
class MovementsTable
{
    public static function configure(Table $table, bool $forAsset = false): Table
    {
        $table
            ->defaultSort(fn (Builder $query): Builder => $query->orderByDesc('moved_on')->orderByDesc('id'))
            ->modifyQueryUsing(fn (Builder $query) => $query->with([
                'asset', 'creator',
                'fromSite', 'fromDepartment', 'fromService', 'fromRoom', 'fromHolder',
                'toSite', 'toDepartment', 'toService', 'toRoom', 'toHolder',
            ]))
            ->emptyStateHeading('Aucun mouvement')
            ->emptyStateDescription($forAsset ? 'Ce bien n\'a pas encore de mouvement.' : 'Les affectations et les transferts apparaîtront ici.')
            ->columns([
                TextColumn::make('moved_on')
                    ->label('Date')
                    ->date('d/m/Y')
                    ->fontFamily('mono')
                    ->sortable(),
                TextColumn::make('asset.number')
                    ->label('Bien')
                    ->fontFamily('mono')
                    ->weight('semibold')
                    ->color('primary')
                    ->description(fn (Movement $record): string => $record->asset->label())
                    ->url(fn (Movement $record): string => AssetResource::getUrl('view', ['record' => $record->asset_id]))
                    ->searchable(query: fn (Builder $query, string $search): Builder => $query->whereHas(
                        'asset',
                        fn (Builder $asset) => $asset->where('number', 'like', "%{$search}%")->orWhere('designation', 'like', "%{$search}%"),
                    ))
                    ->visible(! $forAsset),
                TextColumn::make('type')
                    ->label('Type')
                    ->badge(),
                TextColumn::make('from')
                    ->label('De')
                    ->state(fn (Movement $record): string => $record->assignmentLabel('from'))
                    ->wrap(),
                TextColumn::make('to')
                    ->label('Vers')
                    ->state(fn (Movement $record): string => $record->assignmentLabel('to'))
                    ->weight('semibold')
                    ->wrap(),
                TextColumn::make('reason')
                    ->label('Motif')
                    ->placeholder('—')
                    ->description(fn (Movement $record): ?string => $record->notes)
                    ->wrap(),
                TextColumn::make('creator')
                    ->label('Par')
                    ->state(fn (Movement $record): ?string => $record->creator?->fullName())
                    ->placeholder('Système')
                    ->toggleable(),
            ]);

        if ($forAsset) {
            return $table;
        }

        return $table
            ->filters([
                SelectFilter::make('asset_id')
                    ->label('Bien')
                    ->searchable()
                    ->getSearchResultsUsing(fn (string $search): array => Asset::query()
                        ->where(fn (Builder $query) => $query->where('number', 'like', "%{$search}%")->orWhere('designation', 'like', "%{$search}%"))
                        ->orderBy('number')
                        ->limit(30)
                        ->get()
                        ->mapWithKeys(fn (Asset $asset): array => [$asset->id => $asset->number.' · '.$asset->label()])
                        ->all())
                    ->getOptionLabelUsing(fn (mixed $value): ?string => Asset::query()->find($value)?->number),
                SelectFilter::make('type')
                    ->label('Type')
                    ->options(MovementType::class),
                SelectFilter::make('site')
                    ->label('Site')
                    ->options(fn (): array => Site::query()->orderBy('name')->pluck('name', 'id')->all())
                    ->query(fn (Builder $query, array $data) => $query->when(
                        $data['value'] ?? null,
                        fn (Builder $query, $id) => $query->where(fn (Builder $query) => $query->where('from_site_id', $id)->orWhere('to_site_id', $id)),
                    )),
                SelectFilter::make('department')
                    ->label('Département')
                    ->options(fn (): array => Department::query()->orderBy('name')->pluck('name', 'id')->all())
                    ->query(fn (Builder $query, array $data) => $query->when(
                        $data['value'] ?? null,
                        fn (Builder $query, $id) => $query->where(fn (Builder $query) => $query->where('from_department_id', $id)->orWhere('to_department_id', $id)),
                    )),
                SelectFilter::make('created_by')
                    ->label('Utilisateur')
                    ->options(fn (): array => User::query()->orderBy('last_name')->get()
                        ->mapWithKeys(fn (User $user): array => [$user->id => $user->fullName()])
                        ->all()),
                Filter::make('period')
                    ->label('Période')
                    ->schema([
                        DatePicker::make('from')->label('Du'),
                        DatePicker::make('until')->label('au'),
                    ])
                    ->columns(2)
                    ->query(fn (Builder $query, array $data) => $query
                        ->when($data['from'] ?? null, fn (Builder $query, $date) => $query->whereDate('moved_on', '>=', $date))
                        ->when($data['until'] ?? null, fn (Builder $query, $date) => $query->whereDate('moved_on', '<=', $date))),
            ])
            ->filtersFormColumns(3);
    }
}
