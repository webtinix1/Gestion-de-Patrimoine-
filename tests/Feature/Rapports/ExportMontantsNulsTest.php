<?php

use App\Exports\DepreciationScheduleExport;
use App\Exports\ReportExport;
use App\Models\Asset;
use App\Support\Reports\ReportData;
use Maatwebsite\Excel\Excel as ExcelWriter;
use Maatwebsite\Excel\Facades\Excel;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

function feuilleExcel(object $export): Worksheet
{
    $file = tempnam(sys_get_temp_dir(), 'nul').'.xlsx';
    file_put_contents($file, Excel::raw($export, ExcelWriter::XLSX));
    $sheet = IOFactory::load($file)->getActiveSheet();
    @unlink($file);

    return $sheet;
}

it('écrit une VNC à 0 comme le nombre 0 dans le plan d\'amortissement Excel', function () {
    $asset = Asset::factory()->create(['number' => 'PAT-000900']);
    $report = [
        'unit' => 'Mois',
        'lines' => [[
            'year' => 2026, 'units' => 12, 'original_value' => 120000, 'base' => 120000, 'rate' => 100,
            'amount' => 120000, 'accumulated' => 120000, 'net_book_value' => 0, 'status' => 'Totalement amorti',
        ]],
        'totals' => ['units' => 12, 'amount' => 120000],
    ];

    $sheet = feuilleExcel(new DepreciationScheduleExport($asset, $report));
    $data = DepreciationScheduleExport::HEADER_ROW + 1;
    $cell = $sheet->getCell('H'.$data);

    expect($cell->getValue())->toBe(0)
        ->and($cell->getDataType())->toBe(DataType::TYPE_NUMERIC)
        ->and($sheet->getCell('G'.$data)->getValue())->toBe(120000)
        // L'en-tête est bien à la ligne attendue, en gras : la ligne vide qui le précède est conservée.
        ->and($sheet->getCell('A'.DepreciationScheduleExport::HEADER_ROW)->getValue())->toBe('Exercice')
        ->and($sheet->getStyle('A'.DepreciationScheduleExport::HEADER_ROW)->getFont()->getBold())->toBeTrue();
});

it('écrit un montant à 0 comme le nombre 0 dans l\'export d\'un rapport, et laisse vide un montant absent', function () {
    $data = new ReportData(
        key: 'test', title: 'Test', headings: ['N°', 'Bien', 'VNC', 'Autre'],
        rows: [['PAT-1', 'Bien', 0, null]], moneyColumns: [2], numberColumns: [],
        footerLeft: '', footerRight: '', filters: [], churchName: 'Église', churchAcronym: 'EE',
        editedAt: now(), editedBy: 'Test',
    );

    $sheet = feuilleExcel(new ReportExport($data));
    $zero = null;
    $vide = null;

    foreach ($sheet->getRowIterator() as $row) {
        if ($sheet->getCell('A'.$row->getRowIndex())->getValue() === 'PAT-1') {
            $zero = $sheet->getCell('C'.$row->getRowIndex());
            $vide = $sheet->getCell('D'.$row->getRowIndex());
        }
    }

    expect($zero?->getValue())->toBe(0)
        ->and($vide?->getValue())->toBeNull();
});
