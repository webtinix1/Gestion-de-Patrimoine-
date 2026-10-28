<?php

namespace App\Filament\Resources\AssetReports;

use App\Enums\ReportStatus;
use App\Filament\Resources\AssetReports\Pages\ListAssetReports;
use App\Filament\Resources\AssetReports\Pages\ViewAssetReport;
use App\Filament\Resources\AssetReports\Schemas\AssetReportInfolist;
use App\Filament\Resources\AssetReports\Tables\AssetReportsTable;
use App\Models\AssetReport;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

/**
 * E24, E25 : signalements des départements (RG-63, RG-64). La création passe par l'action « Faire un signalement ».
 */
class AssetReportResource extends Resource
{
    protected static ?string $model = AssetReport::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedFlag;

    protected static string|UnitEnum|null $navigationGroup = 'Opérations';

    protected static ?int $navigationSort = 4;

    protected static bool $hasTitleCaseModelLabel = false;

    protected static ?string $modelLabel = 'signalement';

    protected static ?string $pluralModelLabel = 'Signalements';

    protected static ?string $navigationLabel = 'Signalements';

    protected static ?string $slug = 'signalements';

    protected static ?string $recordTitleAttribute = 'reference';

    public static function canCreate(): bool
    {
        return false;
    }

    public static function getNavigationBadge(): ?string
    {
        $count = AssetReport::query()->where('status', ReportStatus::New)->count();

        return $count > 0 ? (string) $count : null;
    }

    public static function infolist(Schema $schema): Schema
    {
        return AssetReportInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return AssetReportsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListAssetReports::route('/'),
            'view' => ViewAssetReport::route('/{record}'),
        ];
    }
}
