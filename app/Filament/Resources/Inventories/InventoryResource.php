<?php

namespace App\Filament\Resources\Inventories;

use App\Enums\InventoryStatus;
use App\Filament\Resources\Inventories\Pages\CreateInventory;
use App\Filament\Resources\Inventories\Pages\EditInventory;
use App\Filament\Resources\Inventories\Pages\EnterInventory;
use App\Filament\Resources\Inventories\Pages\InventoryReportPage;
use App\Filament\Resources\Inventories\Pages\ListInventories;
use App\Filament\Resources\Inventories\Schemas\InventoryForm;
use App\Filament\Resources\Inventories\Tables\InventoriesTable;
use App\Models\Inventory;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

/**
 * E18 à E21 : inventaires physiques (RG-44 à RG-49).
 */
class InventoryResource extends Resource
{
    protected static ?string $model = Inventory::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClipboardDocumentCheck;

    protected static string|UnitEnum|null $navigationGroup = 'Opérations';

    // Après Mouvements (1), Prêts (2), Maintenance (3) et Signalements (4), avant Sorties.
    protected static ?int $navigationSort = 5;

    protected static bool $hasTitleCaseModelLabel = false;

    protected static ?string $modelLabel = 'inventaire';

    protected static ?string $pluralModelLabel = 'Inventaires';

    protected static ?string $navigationLabel = 'Inventaires';

    protected static ?string $slug = 'inventaires';

    protected static ?string $recordTitleAttribute = 'reference';

    public static function form(Schema $schema): Schema
    {
        return InventoryForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return InventoriesTable::configure($table);
    }

    /**
     * Page à ouvrir selon le statut : modification (planifié), saisie (en cours), rapport (ensuite).
     */
    public static function urlFor(Inventory $inventory): string
    {
        $user = auth()->user();

        return match (true) {
            $inventory->status === InventoryStatus::Planned && ($user?->can('update', $inventory) ?? false) => self::getUrl('edit', ['record' => $inventory]),
            $inventory->status === InventoryStatus::InProgress && ($user?->can('enter', $inventory) ?? false) => self::getUrl('enter', ['record' => $inventory]),
            default => self::getUrl('report', ['record' => $inventory]),
        };
    }

    public static function getPages(): array
    {
        return [
            'index' => ListInventories::route('/'),
            'create' => CreateInventory::route('/create'),
            'edit' => EditInventory::route('/{record}/edit'),
            'enter' => EnterInventory::route('/{record}/saisie'),
            'report' => InventoryReportPage::route('/{record}/rapport'),
        ];
    }
}
