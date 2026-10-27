<?php

namespace App\Filament\Resources\Movements;

use App\Filament\Resources\Movements\Pages\ListMovements;
use App\Filament\Resources\Movements\Tables\MovementsTable;
use App\Models\Movement;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

/**
 * E13 : mouvements en lecture seule. Les transferts se font par l'action « Transférer » (E14).
 */
class MovementResource extends Resource
{
    protected static ?string $model = Movement::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowsRightLeft;

    protected static string|UnitEnum|null $navigationGroup = 'Opérations';

    protected static ?int $navigationSort = 1;

    protected static bool $hasTitleCaseModelLabel = false;

    protected static ?string $modelLabel = 'mouvement';

    protected static ?string $pluralModelLabel = 'Mouvements';

    protected static ?string $navigationLabel = 'Mouvements';

    protected static ?string $slug = 'mouvements';

    public static function canCreate(): bool
    {
        return false;
    }

    public static function table(Table $table): Table
    {
        return MovementsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListMovements::route('/'),
        ];
    }
}
