<?php

namespace App\Filament\Clusters\Referentials;

use BackedEnum;
use Filament\Clusters\Cluster;
use Filament\Pages\Enums\SubNavigationPosition;
use Filament\Support\Icons\Heroicon;
use UnitEnum;

class ReferentialsCluster extends Cluster
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedSquares2x2;

    protected static string|UnitEnum|null $navigationGroup = 'Structure';

    protected static ?int $navigationSort = 4;

    protected static ?string $navigationLabel = 'Catégories et référentiels';

    protected static ?string $clusterBreadcrumb = 'Catégories et référentiels';

    protected static ?string $slug = 'referentiels';

    protected static ?SubNavigationPosition $subNavigationPosition = SubNavigationPosition::Top;
}
