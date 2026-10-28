<?php

namespace App\Filament\Resources\Disposals;

use App\Filament\Resources\Disposals\Pages\CreateDisposal;
use App\Filament\Resources\Disposals\Pages\ListDisposals;
use App\Filament\Resources\Disposals\Pages\ViewDisposal;
use App\Filament\Resources\Disposals\Schemas\DisposalForm;
use App\Filament\Resources\Disposals\Schemas\DisposalInfolist;
use App\Filament\Resources\Disposals\Tables\DisposalsTable;
use App\Models\Disposal;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

/**
 * E26, E27 : sorties du patrimoine. Pas de modification ni de suppression (RG-53).
 */
class DisposalResource extends Resource
{
    protected static ?string $model = Disposal::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArchiveBoxXMark;

    protected static string|UnitEnum|null $navigationGroup = 'Opérations';

    // Dernière entrée du groupe Opérations (docs/04), après les prêts, la maintenance et les inventaires.
    protected static ?int $navigationSort = 90;

    protected static bool $hasTitleCaseModelLabel = false;

    protected static ?string $modelLabel = 'sortie';

    protected static ?string $pluralModelLabel = 'Sorties du patrimoine';

    protected static ?string $navigationLabel = 'Sorties et réformes';

    protected static ?string $slug = 'sorties';

    protected static ?string $recordTitleAttribute = 'reference';

    public static function form(Schema $schema): Schema
    {
        return DisposalForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return DisposalInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return DisposalsTable::configure($table);
    }

    public static function canEdit(Model $record): bool
    {
        return false;
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function getPages(): array
    {
        return [
            'index' => ListDisposals::route('/'),
            'create' => CreateDisposal::route('/create'),
            'view' => ViewDisposal::route('/{record}'),
        ];
    }
}
