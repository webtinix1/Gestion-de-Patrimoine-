<?php

namespace App\Support;

use Carbon\CarbonInterface;

/**
 * Contenu d'un export de la liste des biens (E05), partagé entre Excel et PDF.
 */
final readonly class AssetListData
{
    /**
     * @param  list<string>  $headings
     * @param  list<list<int|string|null>>  $rows
     * @param  list<string>  $filters
     */
    public function __construct(
        public array $headings,
        public array $rows,
        public int $count,
        public int $total,
        public array $filters,
        public string $churchName,
        public string $churchAcronym,
        public CarbonInterface $editedAt,
    ) {}

    public function fileName(string $extension): string
    {
        return 'biens-'.$this->editedAt->format('Y-m-d').'.'.$extension;
    }
}
