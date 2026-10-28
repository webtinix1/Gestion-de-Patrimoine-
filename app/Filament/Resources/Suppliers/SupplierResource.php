<?php

namespace App\Filament\Resources\Suppliers;

use App\Filament\Clusters\Referentials\ReferentialsCluster;
use App\Filament\Resources\Suppliers\Pages\ManageSuppliers;
use App\Filament\Support\Referentials;
use App\Models\Supplier;
use BackedEnum;
use Filament\Actions\ActionGroup;
use Filament\Actions\EditAction;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class SupplierResource extends Resource
{
    protected static ?string $model = Supplier::class;

    protected static ?string $cluster = ReferentialsCluster::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedTruck;

    protected static ?int $navigationSort = 3;

    protected static bool $hasTitleCaseModelLabel = false;

    protected static ?string $modelLabel = 'fournisseur';

    protected static ?string $pluralModelLabel = 'Fournisseurs';

    protected static ?string $navigationLabel = 'Fournisseurs';

    protected static ?string $slug = 'fournisseurs';

    protected static ?string $recordTitleAttribute = 'name';

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('name')
                    ->label('Nom')
                    ->required()
                    ->maxLength(255),
                TextInput::make('phone')
                    ->label('Téléphone')
                    ->tel()
                    ->maxLength(30),
                TextInput::make('address')
                    ->label('Adresse')
                    ->maxLength(255),
                Referentials::activeToggle(),
            ])
            ->columns(1);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('name')
            ->columns([
                TextColumn::make('name')
                    ->label('Fournisseur')
                    ->weight('semibold')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('phone')
                    ->label('Téléphone')
                    ->placeholder('—'),
                TextColumn::make('address')
                    ->label('Adresse')
                    ->placeholder('—')
                    ->searchable(),
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

    public static function getPages(): array
    {
        return [
            'index' => ManageSuppliers::route('/'),
        ];
    }
}
