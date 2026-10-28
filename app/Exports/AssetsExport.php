<?php

namespace App\Exports;

use App\Support\AssetListData;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithColumnFormatting;
use Maatwebsite\Excel\Concerns\WithCustomValueBinder;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithStrictNullComparison;
use Maatwebsite\Excel\Concerns\WithStyles;
use PhpOffice\PhpSpreadsheet\Cell\Cell;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Cell\DefaultValueBinder;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * Export Excel de la liste des biens (E05) : montants en nombres entiers, ligne de total en fin de feuille.
 * Les textes sont écrits comme du texte : une désignation commençant par « = » ne devient jamais une formule.
 */
class AssetsExport extends DefaultValueBinder implements FromArray, ShouldAutoSize, WithColumnFormatting, WithCustomValueBinder, WithHeadings, WithStrictNullComparison, WithStyles
{
    public function __construct(public readonly AssetListData $data) {}

    public function bindValue(Cell $cell, mixed $value): bool
    {
        if (is_string($value)) {
            $cell->setValueExplicit($value, DataType::TYPE_STRING);

            return true;
        }

        return parent::bindValue($cell, $value);
    }

    public function headings(): array
    {
        return $this->data->headings;
    }

    public function array(): array
    {
        $totalRow = array_fill(0, count($this->data->headings), null);
        $totalRow[0] = 'Total ('.$this->data->count.' biens)';
        $totalRow[count($totalRow) - 1] = $this->data->total;

        return [...$this->data->rows, $totalRow];
    }

    public function columnFormats(): array
    {
        return ['L' => '#,##0'];
    }

    public function styles(Worksheet $sheet): array
    {
        $totalLine = $this->data->count + 2;

        return [
            1 => ['font' => ['bold' => true]],
            $totalLine => ['font' => ['bold' => true]],
        ];
    }
}
