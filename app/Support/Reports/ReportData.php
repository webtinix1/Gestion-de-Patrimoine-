<?php

namespace App\Support\Reports;

use Carbon\CarbonInterface;

/**
 * Contenu d'un rapport (E29), partagé entre l'aperçu, le PDF et l'Excel : une seule source des chiffres.
 */
final readonly class ReportData
{
    /**
     * @param  list<string>  $headings
     * @param  list<list<int|string|null>>  $rows
     * @param  list<int>  $moneyColumns  colonnes en FCFA (nombres entiers, alignés à droite)
     * @param  list<int>  $numberColumns  autres colonnes numériques
     * @param  list<string>  $filters
     */
    public function __construct(
        public string $key,
        public string $title,
        public array $headings,
        public array $rows,
        public array $moneyColumns,
        public array $numberColumns,
        public string $footerLeft,
        public string $footerRight,
        public array $filters,
        public string $churchName,
        public string $churchAcronym,
        public CarbonInterface $editedAt,
        public string $editedBy,
        public bool $landscape = true,
    ) {}

    public function count(): int
    {
        return count($this->rows);
    }

    public function subtitle(): string
    {
        return $this->filters === [] ? 'Sans filtre : tout le périmètre de l\'utilisateur' : implode(' · ', $this->filters);
    }

    public function fileName(string $extension): string
    {
        return 'rapport-'.str_replace('_', '-', $this->key).'-'.$this->editedAt->format('Y-m-d').'.'.$extension;
    }
}
