<?php

namespace App\Filament\Resources\Maintenance;

use App\Filament\Resources\Maintenance\Pages\CreateMaintenanceJob;
use App\Filament\Resources\Maintenance\Pages\EditMaintenanceJob;
use App\Filament\Resources\Maintenance\Pages\ListMaintenanceJobs;
use App\Filament\Resources\Maintenance\Pages\ViewMaintenanceJob;
use App\Filament\Resources\Maintenance\Schemas\MaintenanceInfolist;
use App\Filament\Resources\Maintenance\Schemas\MaintenanceJobForm;
use App\Filament\Resources\Maintenance\Tables\MaintenanceJobsTable;
use App\Models\MaintenanceJob;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

/**
 * E22, E23 : maintenance et réparations (RG-60 à RG-62).
 */
class MaintenanceResource extends Resource
{
    protected static ?string $model = MaintenanceJob::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedWrenchScrewdriver;

    protected static string|UnitEnum|null $navigationGroup = 'Opérations';

    protected static ?int $navigationSort = 3;

    protected static bool $hasTitleCaseModelLabel = false;

    protected static ?string $modelLabel = 'intervention';

    protected static ?string $pluralModelLabel = 'Maintenance';

    protected static ?string $navigationLabel = 'Maintenance';

    protected static ?string $slug = 'maintenance';

    protected static ?string $recordTitleAttribute = 'reference';

    public static function form(Schema $schema): Schema
    {
        return MaintenanceJobForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return MaintenanceInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return MaintenanceJobsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListMaintenanceJobs::route('/'),
            'create' => CreateMaintenanceJob::route('/create'),
            'view' => ViewMaintenanceJob::route('/{record}'),
            'edit' => EditMaintenanceJob::route('/{record}/edit'),
        ];
    }
}
