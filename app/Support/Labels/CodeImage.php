<?php

namespace App\Support\Labels;

/**
 * Image d'un code (QR ou Code 128) en data URI, avec sa taille réelle sur l'étiquette, en mm.
 */
final readonly class CodeImage
{
    public function __construct(
        public string $uri,
        public float $widthMm,
        public float $heightMm,
    ) {}
}
