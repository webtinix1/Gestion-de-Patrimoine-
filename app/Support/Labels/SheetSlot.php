<?php

namespace App\Support\Labels;

/**
 * Une case de la planche (ou la seule étiquette d'une page de rouleau). Vide si label est null.
 */
final readonly class SheetSlot
{
    public function __construct(
        public int $number,
        public float $xMm,
        public float $yMm,
        public float $widthMm,
        public float $heightMm,
        public ?ComposedLabel $label,
    ) {}
}
