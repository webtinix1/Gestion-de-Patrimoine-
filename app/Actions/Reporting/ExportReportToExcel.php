<?php

namespace App\Actions\Reporting;

use App\Exports\ReportExport;
use App\Models\User;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * E29 : export Excel d'un rapport, réservé au droit « exporter » du module concerné.
 */
class ExportReportToExcel
{
    public function __construct(private GenerateReport $generate) {}

    /**
     * @param  array<string, mixed>  $filters
     */
    public function __invoke(User $user, string $key, array $filters): BinaryFileResponse
    {
        $data = ($this->generate)($user, $key, $filters, forExport: true);

        return Excel::download(new ReportExport($data), $data->fileName('xlsx'));
    }
}
