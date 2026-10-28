<?php

namespace App\Support\Labels;

/**
 * Résultat d'une impression : le PDF à l'échelle exacte et ce qu'il contient.
 */
final readonly class PrintedLabels
{
    public function __construct(
        public string $pdf,
        public string $fileName,
        public int $labelCount,
        public int $pageCount,
    ) {}
}
