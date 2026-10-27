<?php

namespace App\Filament\Resources\Acquisitions;

use App\Filament\Resources\Acquisitions\Pages\CreateAcquisition;
use App\Filament\Resources\Acquisitions\Pages\EditAcquisition;
use App\Filament\Resources\Acquisitions\Pages\ListAcquisitions;
use App\Filament\Resources\Acquisitions\Pages\ViewAcquisition;
use App\Filament\Resources\Acquisitions\RelationManagers\DocumentsRelationManager;
use App\Filament\Resources\Acquisitions\Schemas\AcquisitionForm;
use App\Filament\Resources\Acquisitions\Schemas\AcquisitionInfolist;
use App\Filament\Resources\Acquisitions\Tables\AcquisitionsTable;
use App\Models\Acquisition;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

/**
 * E11, E12 : acquisitions (RG-15, RG-16).
 */
class AcquisitionResource extends Resource
{
    protected static ?string $model = Acquisition::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedShoppingCart;

    protected static string|UnitEnum|null $navigationGroup = 'Patrimoine';

    protected static ?int $navigationSort = 4;

    protected static bool $hasTitleCaseModelLabel = false;

    protected static ?string $modelLabel = 'acquisition';

    protected static ?string $pluralModelLabel = 'Acquisitions';

    protected static ?string $navigationLabel = 'Acquisitions';

    protected static ?string $slug = 'acquisitions';

    protected static ?string $recordTitleAttribute = 'reference';

    public static function form(Schema $schema): Schema
    {
        return AcquisitionForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return AcquisitionInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return AcquisitionsTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            DocumentsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListAcquisitions::route('/'),
            'create' => CreateAcquisition::route('/create'),
            'view' => ViewAcquisition::route('/{record}'),
            'edit' => EditAcquisition::route('/{record}/edit'),
        ];
    }
}
