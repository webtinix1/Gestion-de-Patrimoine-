<?php

namespace App\Exports;

use App\Models\Asset;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithColumnFormatting;
use Maatwebsite\Excel\Concerns\WithCustomValueBinder;
use Maatwebsite\Excel\Concerns\WithStrictNullComparison;
use Maatwebsite\Excel\Concerns\WithStyles;
use Maatwebsite\Excel\Concerns\WithTitle;
use PhpOffice\PhpSpreadsheet\Cell\Cell;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Cell\DefaultValueBinder;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * Export Excel d'un plan d'amortissement (E31) : montants en nombres entiers, textes forcés en texte.
 */
class DepreciationScheduleExport extends DefaultValueBinder implements FromArray, ShouldAutoSize, WithColumnFormatting, WithCustomValueBinder, WithStrictNullComparison, WithStyles, WithTitle
{
    public const int HEADER_ROW = 4;

    /**
     * @param  array<string, mixed>  $report
     */
    public function __construct(public readonly Asset $asset, public readonly array $report) {}

    public function bindValue(Cell $cell, mixed $value): bool
    {
        if (is_string($value)) {
            $cell->setValueExplicit($value, DataType::TYPE_STRING);

            return true;
        }

        return parent::bindValue($cell, $value);
    }

    public function title(): string
    {
        return 'Plan '.$this->asset->number;
    }

    public function array(): array
    {
        /** @var list<array<string, mixed>> $lines */
        $lines = $this->report['lines'];
        $rows = [
            ['Plan d\'amortissement '.$this->asset->number, $this->asset->label()],
            ['Montants en FCFA'],
            [null], // ligne vide : un tableau vide serait supprimé à l'écriture.
            ['Exercice', (string) $this->report['unit'], 'Valeur d\'origine', 'Base', 'Taux', 'Dotation', 'Cumul', 'VNC fin d\'exercice', 'Statut'],
        ];

        foreach ($lines as $line) {
            $rows[] = [
                $line['year'], $line['units'], $line['original_value'], $line['base'], $line['rate'],
                $line['amount'], $line['accumulated'], $line['net_book_value'], $line['status'],
            ];
        }

        $rows[] = ['Total', $this->report['totals']['units'], null, null, null, $this->report['totals']['amount']];

        return $rows;
    }

    public function columnFormats(): array
    {
        return ['C' => '#,##0', 'D' => '#,##0', 'F' => '#,##0', 'G' => '#,##0', 'H' => '#,##0'];
    }

    public function styles(Worksheet $sheet): array
    {
        return [
            1 => ['font' => ['bold' => true]],
            self::HEADER_ROW => ['font' => ['bold' => true]],
            self::HEADER_ROW + count($this->report['lines']) + 1 => ['font' => ['bold' => true]],
        ];
    }
}
