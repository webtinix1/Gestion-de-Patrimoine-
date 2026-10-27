<?php

namespace App\Filament\Resources\Rooms\Tables;

use App\Enums\RoomType;
use App\Filament\Support\Referentials;
use Filament\Actions\ActionGroup;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Grouping\Group;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class RoomsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['site', 'department']))
            ->defaultGroup(Group::make('site.name')->label('Site')->collapsible())
            ->defaultSort('name')
            ->columns([
                TextColumn::make('code')
                    ->label('Code')
                    ->fontFamily('mono')
                    ->weight('semibold')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('name')
                    ->label('Local')
                    ->weight('semibold')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('type')
                    ->label('Type')
                    ->badge(),
                TextColumn::make('department.name')
                    ->label('Département')
                    ->badge()
                    ->color('primary')
                    ->placeholder('—'),
                Referentials::activeColumn(),
            ])
            ->filters([
                SelectFilter::make('site_id')
                    ->label('Site')
                    ->relationship('site', 'name')
                    ->preload(),
                SelectFilter::make('type')
                    ->label('Type')
                    ->options(RoomType::class),
                SelectFilter::make('department_id')
                    ->label('Département')
                    ->relationship('department', 'name')
                    ->preload(),
                Referentials::activeFilter(),
            ])
            ->recordActions([
                EditAction::make(),
                ActionGroup::make(Referentials::recordActions()),
            ]);
    }
}
