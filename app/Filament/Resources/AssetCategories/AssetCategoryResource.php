<?php

namespace App\Filament\Resources\AssetCategories;

use App\Filament\Clusters\Referentials\ReferentialsCluster;
use App\Filament\Resources\AssetCategories\Pages\CreateAssetCategory;
use App\Filament\Resources\AssetCategories\Pages\EditAssetCategory;
use App\Filament\Resources\AssetCategories\Pages\ListAssetCategories;
use App\Filament\Resources\AssetCategories\Schemas\AssetCategoryForm;
use App\Filament\Resources\AssetCategories\Tables\AssetCategoriesTable;
use App\Models\AssetCategory;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class AssetCategoryResource extends Resource
{
    protected static ?string $model = AssetCategory::class;

    protected static ?string $cluster = ReferentialsCluster::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedTag;

    protected static ?int $navigationSort = 1;

    protected static bool $hasTitleCaseModelLabel = false;

    protected static ?string $modelLabel = 'catégorie';

    protected static ?string $pluralModelLabel = 'Catégories de biens';

    protected static ?string $navigationLabel = 'Catégories de biens';

    protected static ?string $slug = 'categories';

    protected static ?string $recordTitleAttribute = 'name';

    public static function form(Schema $schema): Schema
    {
        return AssetCategoryForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return AssetCategoriesTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListAssetCategories::route('/'),
            'create' => CreateAssetCategory::route('/create'),
            'edit' => EditAssetCategory::route('/{record}/edit'),
        ];
    }
}
