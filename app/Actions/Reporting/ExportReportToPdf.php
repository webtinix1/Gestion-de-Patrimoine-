<?php

namespace App\Actions\Reporting;

use App\Models\User;
use App\Support\Pdf\PdfFactory;
use App\Support\Reports\ReportData;
use Barryvdh\DomPDF\PDF as DomPdf;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * E29, E45 : export PDF d'un rapport (A4 paysage), réservé au droit « exporter » du module concerné.
 */
class ExportReportToPdf
{
    public function __construct(private GenerateReport $generate) {}

    /**
     * @param  array<string, mixed>  $filters
     */
    public function __invoke(User $user, string $key, array $filters): StreamedResponse
    {
        $data = ($this->generate)($user, $key, $filters, forExport: true);
        $pdf = $this->render($data);

        return response()->streamDownload(function () use ($pdf): void {
            echo $pdf->output();
        }, $data->fileName('pdf'), ['Content-Type' => 'application/pdf']);
    }

    public function render(ReportData $data): DomPdf
    {
        return PdfFactory::view('pdf.report', ['data' => $data], landscape: $data->landscape);
    }
}
