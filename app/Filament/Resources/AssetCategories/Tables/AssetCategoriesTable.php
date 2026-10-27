<?php

namespace App\Filament\Resources\AssetCategories\Tables;

use App\Enums\Depreciability;
use App\Filament\Resources\AssetCategories\Schemas\AssetCategoryForm;
use App\Filament\Support\Referentials;
use App\Models\AssetCategory;
use Filament\Actions\ActionGroup;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class AssetCategoriesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('name')
            ->columns([
                TextColumn::make('name')
                    ->label('Catégorie')
                    ->weight('semibold')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('prefix')
                    ->label('Préfixe')
                    ->fontFamily('mono')
                    ->weight('semibold')
                    ->searchable(),
                TextColumn::make('depreciable')
                    ->label('Amortissable')
                    ->badge(),
                TextColumn::make('default_duration_years')
                    ->label('Durée')
                    ->formatStateUsing(fn (AssetCategory $record): string => $record->depreciable === Depreciability::No || ! $record->default_duration_years
                        ? '—'
                        : $record->default_duration_years.' ans')
                    ->placeholder('—'),
                TextColumn::make('rate')
                    ->label('Taux')
                    ->state(fn (AssetCategory $record): string => $record->depreciable === Depreciability::No
                        ? '—'
                        : AssetCategoryForm::formatRate($record->default_duration_years))
                    ->fontFamily('mono'),
                TextColumn::make('asset_account')
                    ->label('Compte d\'immo.')
                    ->fontFamily('mono')
                    ->placeholder('—')
                    ->toggleable(),
                TextColumn::make('depreciation_account')
                    ->label('Compte d\'amort.')
                    ->fontFamily('mono')
                    ->placeholder('—')
                    ->toggleable(),
                Referentials::activeColumn(),
            ])
            ->filters([
                SelectFilter::make('depreciable')
                    ->label('Amortissable')
                    ->options(Depreciability::class),
                Referentials::activeFilter(),
            ])
            ->recordActions([
                EditAction::make(),
                ActionGroup::make(Referentials::recordActions()),
            ]);
    }
}
