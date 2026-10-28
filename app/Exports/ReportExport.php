<?php

namespace App\Exports;

use App\Support\Reports\ReportData;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithColumnFormatting;
use Maatwebsite\Excel\Concerns\WithCustomValueBinder;
use Maatwebsite\Excel\Concerns\WithStrictNullComparison;
use Maatwebsite\Excel\Concerns\WithStyles;
use Maatwebsite\Excel\Concerns\WithTitle;
use PhpOffice\PhpSpreadsheet\Cell\Cell;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Cell\DefaultValueBinder;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * E29 : export Excel d'un rapport. Montants en nombres entiers ; tout texte est écrit comme du texte, jamais comme
 * une formule : une désignation commençant par « = », « + », « - » ou « @ » ne s'exécute pas dans Excel.
 */
class ReportExport extends DefaultValueBinder implements FromArray, ShouldAutoSize, WithColumnFormatting, WithCustomValueBinder, WithStrictNullComparison, WithStyles, WithTitle
{
    public function __construct(public readonly ReportData $data) {}

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
        return mb_substr(preg_replace('/[\\\\\/?*\[\]:]/', ' ', $this->data->title) ?? 'Rapport', 0, 31);
    }

    /** Ligne des intitulés de colonnes (après l'en-tête du rapport). */
    public const int HEADING_ROW = 6;

    public function array(): array
    {
        $summary = array_fill(0, count($this->data->headings), null);
        $summary[0] = $this->data->footerLeft;
        $last = count($summary) - 1;

        if ($last > 0) {
            $summary[$last] = $this->data->footerRight;
        }

        // En-tête : Église, rapport, filtres appliqués, édition.
        $church = $this->data->churchName.($this->data->churchAcronym === '' ? '' : ' ('.$this->data->churchAcronym.')');

        return [
            [$church.' · Gestion du patrimoine'],
            [$this->data->title],
            [$this->data->subtitle()],
            ['Édité le '.$this->data->editedAt->format('d/m/Y à H:i').' par '.$this->data->editedBy],
            [null],
            $this->data->headings,
            ...$this->data->rows,
            $summary,
        ];
    }

    public function columnFormats(): array
    {
        $formats = [];

        foreach ($this->data->moneyColumns as $index) {
            $formats[Coordinate::stringFromColumnIndex($index + 1)] = '#,##0';
        }

        return $formats;
    }

    public function styles(Worksheet $sheet): array
    {
        return [
            1 => ['font' => ['bold' => true]],
            2 => ['font' => ['bold' => true, 'size' => 14]],
            self::HEADING_ROW => ['font' => ['bold' => true]],
            self::HEADING_ROW + $this->data->count() + 1 => ['font' => ['bold' => true]],
        ];
    }
}
