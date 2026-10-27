<?php

namespace App\Filament\Resources\AssetConditions;

use App\Filament\Clusters\Referentials\ReferentialsCluster;
use App\Filament\Resources\AssetConditions\Pages\ManageAssetConditions;
use App\Filament\Support\Referentials;
use App\Models\AssetCondition;
use BackedEnum;
use Filament\Actions\ActionGroup;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/**
 * RG-11 : états paramétrables (condition physique du bien), ordonnables.
 */
class AssetConditionResource extends Resource
{
    protected static ?string $model = AssetCondition::class;

    protected static ?string $cluster = ReferentialsCluster::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedSwatch;

    protected static ?int $navigationSort = 2;

    protected static bool $hasTitleCaseModelLabel = false;

    protected static ?string $modelLabel = 'état';

    protected static ?string $pluralModelLabel = 'États';

    protected static ?string $navigationLabel = 'États';

    protected static ?string $slug = 'etats';

    protected static ?string $recordTitleAttribute = 'label';

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('label')
                    ->label('Libellé')
                    ->required()
                    ->maxLength(100)
                    ->unique(ignoreRecord: true),
                Select::make('color')
                    ->label('Couleur du badge')
                    ->options(AssetCondition::COLORS)
                    ->default('gray')
                    ->selectablePlaceholder(false)
                    ->required(),
                Referentials::activeToggle(),
            ])
            ->columns(1);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->description('L\'état décrit la condition physique ou fonctionnelle du bien. Glissez les lignes pour les réordonner.')
            ->reorderable('sort')
            ->defaultSort('sort')
            ->columns([
                TextColumn::make('label')
                    ->label('État')
                    ->weight('semibold')
                    ->searchable(),
                TextColumn::make('preview')
                    ->label('Aperçu')
                    ->state(fn (AssetCondition $record): string => $record->label)
                    ->badge()
                    ->color(fn (AssetCondition $record): string => $record->color),
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
            'index' => ManageAssetConditions::route('/'),
        ];
    }
}
