<?php

namespace App\Filament\Clusters\Referentials\Pages;

use App\Enums\AssetStatus;
use App\Enums\DisposalReason;
use App\Enums\FundingSource;
use App\Filament\Clusters\Referentials\ReferentialsCluster;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;

/**
 * Valeurs fixes (Enums) affichées en lecture seule : statuts (RG-11, RG-12),
 * motifs de sortie (RG-50) et modes de financement.
 */
class StatusesAndReasons extends Page
{
    protected static ?string $cluster = ReferentialsCluster::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedListBullet;

    protected static ?int $navigationSort = 5;

    protected static ?string $navigationLabel = 'Statuts, motifs et financements';

    protected static ?string $title = 'Statuts, motifs et financements';

    protected static ?string $slug = 'statuts-motifs';

    protected string $view = 'filament.clusters.referentials.pages.statuses-and-reasons';

    public static function canAccess(): bool
    {
        return ReferentialsCluster::canAccess();
    }

    /**
     * @return array<string, mixed>
     */
    protected function getViewData(): array
    {
        return [
            'statuses' => AssetStatus::cases(),
            'disposalReasons' => DisposalReason::cases(),
            'fundingSources' => FundingSource::cases(),
        ];
    }
}
