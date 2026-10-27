<?php

namespace App\Filament\Resources\Assets\Tables;

use App\Enums\AssetStatus;
use App\Filament\Actions\TransferAction;
use App\Models\Asset;
use App\Models\AssetCondition;
use App\Support\Money;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * E05 : liste des biens, filtres de l'écran, recherche par numéro, désignation, marque, n° de série.
 */
class AssetsTable
{
    public const string ACTIVE = 'active';

    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('number', 'desc')
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['category', 'site', 'department', 'room', 'holder', 'condition']))
            ->searchPlaceholder('N°, désignation, marque, n° de série…')
            ->columns([
                TextColumn::make('number')
                    ->label('N° patrimonial')
                    ->fontFamily('mono')
                    ->weight('semibold')
                    ->color('primary')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('designation')
                    ->label('Désignation')
                    ->description(fn (Asset $record): ?string => trim(($record->brand ?? '').' '.($record->model ?? '')) ?: null)
                    ->searchable(['designation', 'brand', 'model', 'serial_number'])
                    ->sortable(),
                TextColumn::make('category.name')
                    ->label('Catégorie')
                    ->toggleable(),
                TextColumn::make('site.name')
                    ->label('Site · Département')
                    ->description(fn (Asset $record): string => $record->department->name),
                TextColumn::make('room.name')
                    ->label('Local')
                    ->placeholder('—')
                    ->toggleable(),
                TextColumn::make('holder.name')
                    ->label('Responsable')
                    ->placeholder('—')
                    ->toggleable(),
                TextColumn::make('condition.label')
                    ->label('État')
                    ->badge()
                    ->color(fn (Asset $record): string => $record->condition->color),
                TextColumn::make('status')
                    ->label('Statut')
                    ->badge(),
                TextColumn::make('acquisition_cost')
                    ->label('Valeur (FCFA)')
                    ->formatStateUsing(fn (?int $state): string => Money::format($state))
                    ->fontFamily('mono')
                    ->alignEnd()
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('asset_category_id')->label('Catégorie')->relationship('category', 'name')->preload(),
                SelectFilter::make('site_id')->label('Site')->relationship('site', 'name')->preload(),
                SelectFilter::make('department_id')->label('Département')->relationship('department', 'name')->preload(),
                SelectFilter::make('service_id')->label('Service')->relationship('service', 'name')->searchable()->preload(),
                SelectFilter::make('status')
                    ->label('Statut')
                    ->options([self::ACTIVE => 'Actifs (hors sortis)', ...collect(AssetStatus::cases())->mapWithKeys(fn (AssetStatus $status) => [$status->value => $status->getLabel()])->all()])
                    ->default(self::ACTIVE)
                    ->query(fn (Builder $query, array $data) => match ($data['value'] ?? null) {
                        null, '' => $query,
                        self::ACTIVE => $query->whereNull('disposed_at'),
                        default => $query->where('status', $data['value']),
                    }),
                SelectFilter::make('asset_condition_id')
                    ->label('État')
                    ->options(fn (): array => AssetCondition::query()->orderBy('sort')->pluck('label', 'id')->all()),
                SelectFilter::make('room_id')->label('Local')->relationship('room', 'name')->searchable()->preload(),
                SelectFilter::make('holder_id')->label('Responsable')->relationship('holder', 'name')->searchable()->preload(),
                Filter::make('acquired_between')
                    ->label('Période d\'acquisition')
                    ->schema([
                        DatePicker::make('from')->label('Acquis du'),
                        DatePicker::make('until')->label('au'),
                    ])
                    ->columns(2)
                    ->query(fn (Builder $query, array $data) => $query
                        ->when($data['from'] ?? null, fn (Builder $query, $date) => $query->whereDate('acquired_on', '>=', $date))
                        ->when($data['until'] ?? null, fn (Builder $query, $date) => $query->whereDate('acquired_on', '<=', $date))),
            ])
            ->filtersFormColumns(3)
            ->recordUrl(fn (Asset $record): string => route('filament.admin.resources.biens.view', $record))
            ->recordActions([
                ViewAction::make(),
                EditAction::make(),
            ])
            ->toolbarActions([
                TransferAction::bulk(),
            ]);
    }
}
