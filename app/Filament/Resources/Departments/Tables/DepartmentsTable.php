<?php

namespace App\Filament\Resources\Departments\Tables;

use App\Filament\Support\Referentials;
use App\Models\Department;
use Filament\Actions\ActionGroup;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class DepartmentsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('name')
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['manager', 'sites', 'services'])->withCount('services'))
            ->columns([
                TextColumn::make('code')
                    ->label('Code')
                    ->fontFamily('mono')
                    ->weight('semibold')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('name')
                    ->label('Département')
                    ->weight('semibold')
                    ->description(fn (Department $record): string => $record->sites->isEmpty()
                        ? 'Aucun site'
                        : $record->sites->pluck('name')->join(', '))
                    ->searchable()
                    ->sortable(),
                TextColumn::make('services.name')
                    ->label('Services')
                    ->badge()
                    ->color('primary')
                    ->listWithLineBreaks()
                    ->limitList(4)
                    ->expandableLimitedList(),
                TextColumn::make('manager_id')
                    ->label('Responsable')
                    ->formatStateUsing(fn (Department $record): ?string => $record->manager?->fullName()),
                Referentials::activeColumn(),
            ])
            ->filters([
                SelectFilter::make('sites')
                    ->label('Site')
                    ->relationship('sites', 'name')
                    ->preload(),
                Referentials::activeFilter(),
            ])
            ->recordActions([
                EditAction::make(),
                ActionGroup::make(Referentials::recordActions()),
            ]);
    }
}
