<?php

namespace App\Filament\Resources\Sites\Tables;

use App\Filament\Support\Referentials;
use App\Models\Site;
use Filament\Actions\ActionGroup;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class SitesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('name')
            ->modifyQueryUsing(fn (Builder $query) => $query->with('manager')->withCount(['departments', 'rooms']))
            ->columns([
                TextColumn::make('code')
                    ->label('Code')
                    ->fontFamily('mono')
                    ->weight('semibold')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('name')
                    ->label('Site')
                    ->weight('semibold')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('address')
                    ->label('Localisation')
                    ->searchable()
                    ->toggleable(),
                TextColumn::make('manager_id')
                    ->label('Responsable')
                    ->formatStateUsing(fn (Site $record): ?string => $record->manager?->fullName()),
                TextColumn::make('departments_count')
                    ->label('Départements')
                    ->numeric(),
                TextColumn::make('rooms_count')
                    ->label('Locaux')
                    ->numeric(),
                Referentials::activeColumn(),
            ])
            ->filters([
                Referentials::activeFilter(),
            ])
            ->recordActions([
                EditAction::make(),
                ActionGroup::make(Referentials::recordActions()),
            ]);
    }
}
