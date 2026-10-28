<?php

namespace App\Actions\Assets;

use App\Exports\AssetsExport;
use App\Models\Asset;
use App\Services\AssetListReport;
use Illuminate\Database\Eloquent\Builder;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * E05 : export Excel des biens de la liste à l'écran.
 */
class ExportAssetsToExcel
{
    public function __construct(private AssetListReport $report) {}

    /**
     * @param  Builder<Asset>  $query
     * @param  list<string>  $filters
     */
    public function __invoke(Builder $query, array $filters = []): BinaryFileResponse
    {
        $data = $this->report->build($query, $filters);

        return Excel::download(new AssetsExport($data), $data->fileName('xlsx'));
    }
}
