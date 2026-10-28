<?php

namespace App\Filament\Resources\Assets\RelationManagers;

use App\Filament\Resources\Movements\Tables\MovementsTable;
use App\Models\Asset;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Table;

/**
 * E06 : onglet « Affectation et mouvements », en lecture seule. L'affectation actuelle en tête, puis l'historique.
 */
class MovementsRelationManager extends RelationManager
{
    protected static string $relationship = 'movements';

    protected static ?string $title = 'Affectation et mouvements';

    protected static ?string $modelLabel = 'mouvement';

    protected static ?string $pluralModelLabel = 'mouvements';

    public function isReadOnly(): bool
    {
        return true;
    }

    public function table(Table $table): Table
    {
        /** @var Asset $asset */
        $asset = $this->getOwnerRecord();
        $asset->loadMissing(['site', 'department', 'service', 'room', 'holder']);

        $current = collect([$asset->site, $asset->department, $asset->service, $asset->room, $asset->holder])
            ->filter()
            ->map(fn ($part): string => $part->name)
            ->join(' · ');

        return MovementsTable::configure($table, forAsset: true)
            ->heading('Affectation actuelle')
            ->description($current.($asset->assigned_since ? ' · depuis le '.$asset->assigned_since->format('d/m/Y') : ''));
    }
}
