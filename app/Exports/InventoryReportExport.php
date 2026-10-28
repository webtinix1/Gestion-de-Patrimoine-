<?php

namespace App\Exports;

use App\Models\Inventory;
use App\Models\InventoryLine;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithCustomValueBinder;
use Maatwebsite\Excel\Concerns\WithStyles;
use Maatwebsite\Excel\Concerns\WithTitle;
use PhpOffice\PhpSpreadsheet\Cell\Cell;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Cell\DefaultValueBinder;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * RG-49 : rapport d'inventaire en Excel, une ligne par bien avec l'attendu, le constaté et la
 * régularisation. Textes forcés en texte (aucune formule possible depuis une saisie).
 */
class InventoryReportExport extends DefaultValueBinder implements FromArray, ShouldAutoSize, WithCustomValueBinder, WithStyles, WithTitle
{
    public const int HEADER_ROW = 5;

    /**
     * @param  array<string, int>  $counters
     * @param  Collection<int, InventoryLine>  $lines
     */
    public function __construct(
        public readonly Inventory $inventory,
        public readonly array $counters,
        public readonly Collection $lines,
        public readonly ?string $perimeterNote = null,
    ) {}

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
        return 'Inventaire '.$this->inventory->reference;
    }

    public function array(): array
    {
        $rows = [
            ['Rapport d\'inventaire '.$this->inventory->reference, $this->inventory->scopeLabel(), 'Du '.$this->inventory->periodLabel(), $this->inventory->status->getLabel()],
            ['Attendus', $this->counters['expected'], 'Présents', $this->counters['present'], 'Manquants', $this->counters['missing'],
                'Écarts', $this->counters['discrepancies'], 'Non enregistrés', $this->counters['unregistered'], 'Hors périmètre', $this->counters['out_of_scope'], 'Justifiés', $this->counters['justified']],
            ['Clôture', $this->inventory->closed_at?->format('d/m/Y H:i') ?? '—', 'Commentaire', $this->inventory->closing_comment ?? ''],
            $this->perimeterNote === null ? [] : [$this->perimeterNote],
            ['N°', 'Désignation', 'Attendu', 'Site', 'Département attendu', 'Local attendu', 'État attendu', 'Résultat', 'Département constaté',
                'Local constaté', 'État constaté', 'Observations', 'Pointé par', 'Pointé le', 'Régularisation'],
        ];

        foreach ($this->lines as $line) {
            $rows[] = [
                $line->asset->number ?? '—',
                $line->asset?->label() ?? (string) $line->description,
                $line->expected ? 'Oui' : 'Non',
                ($line->asset_id ? $line->expectedSite : $line->foundSite)->name ?? '',
                $line->expectedDepartment->name ?? '',
                $line->expectedRoom->name ?? '',
                $line->expectedCondition->label ?? '',
                $line->result->getLabel(),
                $line->foundDepartment->name ?? '',
                $line->foundRoom->name ?? '',
                $line->foundCondition->label ?? '',
                $line->notes ?? '',
                $line->checker?->fullName() ?? ($line->checked_at ? 'Application' : ''),
                $line->checked_at?->format('d/m/Y H:i') ?? '',
                $line->resolution?->getLabel() ?? '',
            ];
        }

        return $rows;
    }

    public function styles(Worksheet $sheet): array
    {
        return [
            1 => ['font' => ['bold' => true]],
            self::HEADER_ROW => ['font' => ['bold' => true]],
        ];
    }
}
