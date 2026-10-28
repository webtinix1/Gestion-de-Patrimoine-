<?php

namespace App\Actions\Depreciation;

use App\Exports\DepreciationScheduleExport;
use App\Models\Asset;
use App\Services\DepreciationPlanReport;
use App\Services\Settings;
use App\Support\Pdf\PdfFactory;
use Barryvdh\DomPDF\PDF as DomPdf;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * E31, E45 : exports PDF (gabarit imprimé) et Excel du plan d'amortissement d'un bien.
 */
class ExportDepreciationSchedule
{
    public function __construct(
        private DepreciationPlanReport $report,
        private Settings $settings,
    ) {}

    public function pdf(Asset $asset): StreamedResponse
    {
        $pdf = $this->render($asset);

        return response()->streamDownload(
            function () use ($pdf): void {
                echo $pdf->output();
            },
            $this->fileName($asset, 'pdf'),
            ['Content-Type' => 'application/pdf'],
        );
    }

    public function excel(Asset $asset): BinaryFileResponse
    {
        return Excel::download(new DepreciationScheduleExport($asset, $this->report->build($asset)), $this->fileName($asset, 'xlsx'));
    }

    public function render(Asset $asset): DomPdf
    {
        // Hors ligne : aucune ressource distante, police DejaVu Sans incluse dans dompdf.
        return PdfFactory::view('pdf.depreciation-schedule', [
            'asset' => $asset,
            'report' => $this->report->build($asset),
            'churchName' => $this->settings->string(Settings::CHURCH_NAME),
            'churchAcronym' => $this->settings->string(Settings::CHURCH_ACRONYM),
            'editedAt' => now(),
        ]);
    }

    private function fileName(Asset $asset, string $extension): string
    {
        return 'plan-amortissement-'.$asset->number.'-'.now()->format('Ymd-His').'.'.$extension;
    }
}
