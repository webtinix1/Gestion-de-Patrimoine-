<?php

namespace App\Actions\Assets;

use App\Models\Asset;
use App\Services\AssetListReport;
use App\Support\AssetListData;
use Barryvdh\DomPDF\Facade\Pdf;
use Barryvdh\DomPDF\PDF as DomPdf;
use Illuminate\Database\Eloquent\Builder;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * E05 : export PDF (A4 paysage) des biens de la liste à l'écran.
 */
class ExportAssetsToPdf
{
    public function __construct(private AssetListReport $report) {}

    /**
     * @param  Builder<Asset>  $query
     * @param  list<string>  $filters
     */
    public function __invoke(Builder $query, array $filters = []): StreamedResponse
    {
        $data = $this->report->build($query, $filters);
        $pdf = $this->render($data);

        return response()->streamDownload(
            function () use ($pdf): void {
                echo $pdf->output();
            },
            $data->fileName('pdf'),
            ['Content-Type' => 'application/pdf'],
        );
    }

    public function render(AssetListData $data): DomPdf
    {
        // Hors ligne : aucune ressource distante, police DejaVu Sans incluse dans dompdf.
        return Pdf::loadView('pdf.assets-list', ['data' => $data])
            ->setPaper('a4', 'landscape')
            ->setOption([
                'enable_remote' => false,
                'enable_php' => false,
                'default_font' => 'DejaVu Sans',
            ]);
    }
}
