<?php

namespace App\Filament\Resources\AssetReports\Pages;

use App\Filament\Actions\AssetReportActions;
use App\Filament\Resources\AssetReports\AssetReportResource;
use App\Filament\Support\HoldsOperationVersions;
use App\Models\AssetReport;
use Filament\Resources\Pages\ViewRecord;
use Illuminate\Contracts\Support\Htmlable;

class ViewAssetReport extends ViewRecord
{
    use HoldsOperationVersions;

    protected static string $resource = AssetReportResource::class;

    public function getTitle(): string|Htmlable
    {
        /** @var AssetReport $report */
        $report = $this->getRecord();

        return 'Signalement '.$report->reference;
    }

    protected function getHeaderActions(): array
    {
        return AssetReportActions::processing();
    }
}
