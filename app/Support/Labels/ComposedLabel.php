<?php

namespace App\Support\Labels;

/**
 * Étiquette mise en page : tout est en millimètres, depuis son coin supérieur gauche.
 *
 * @phpstan-type Line array{text: string, x: float, y: float, w: float, h: float, pt: float, bold: bool, mono: bool}
 */
final readonly class ComposedLabel
{
    /**
     * @param  list<Line>  $lines
     */
    public function __construct(
        public float $widthMm,
        public float $heightMm,
        public CodeImage $code,
        public float $codeX,
        public float $codeY,
        public array $lines,
    ) {}
}
