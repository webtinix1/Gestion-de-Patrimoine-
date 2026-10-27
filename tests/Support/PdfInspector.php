<?php

namespace Tests\Support;

/**
 * Lit un PDF produit par DomPDF : nombre de pages, taille des pages (en mm) et position des images
 * (en mm depuis le coin supérieur gauche), pour vérifier l'échelle exacte des étiquettes.
 */
final class PdfInspector
{
    private const float PT_TO_MM = 25.4 / 72;

    /** @var list<string> */
    private array $streams = [];

    public function __construct(private string $pdf)
    {
        preg_match_all('/stream\r?\n(.*?)\r?\nendstream/s', $pdf, $matches);

        foreach ($matches[1] as $raw) {
            $decoded = @gzuncompress($raw);
            $this->streams[] = $decoded === false ? $raw : $decoded;
        }
    }

    public function pageCount(): int
    {
        return (int) preg_match_all('/\/Type\s*\/Page(?!s)\b/', $this->pdf);
    }

    /**
     * @return list<array{0: float, 1: float}> largeur et hauteur de chaque page, en mm
     */
    public function pageSizesMm(): array
    {
        $sizes = [];

        foreach (explode('endobj', $this->pdf) as $object) {
            if (! preg_match('/\/Type\s*\/Page(?!s)\b/', $object)
                || ! preg_match('/\/MediaBox\s*\[\s*([\d.\-]+)\s+([\d.\-]+)\s+([\d.\-]+)\s+([\d.\-]+)\s*\]/', $object, $box)) {
                continue;
            }

            $sizes[] = [
                round(((float) $box[3] - (float) $box[1]) * self::PT_TO_MM, 2),
                round(((float) $box[4] - (float) $box[2]) * self::PT_TO_MM, 2),
            ];
        }

        return $sizes;
    }

    /**
     * Images dessinées, par page, triées de haut en bas puis de gauche à droite :
     * x, y (depuis le haut), largeur et hauteur en mm.
     *
     * @return list<list<array{x: float, y: float, w: float, h: float}>>
     */
    public function imagesByPage(): array
    {
        $heights = array_map(fn (array $size): float => $size[1] / self::PT_TO_MM, $this->pageSizesMm());
        $pages = [];

        foreach ($this->streams as $stream) {
            if (! preg_match_all('/([\d.\-]+) 0 0 ([\d.\-]+) ([\d.\-]+) ([\d.\-]+) cm\s+\/\w+ Do/', $stream, $matches, PREG_SET_ORDER)) {
                continue;
            }

            $pageHeight = $heights[count($pages)] ?? end($heights);
            $images = [];

            foreach ($matches as $match) {
                $w = (float) $match[1];
                $h = (float) $match[2];
                $x = (float) $match[3];
                $yBottom = (float) $match[4];

                $images[] = [
                    'x' => round($x * self::PT_TO_MM, 2),
                    'y' => round(($pageHeight - $yBottom - $h) * self::PT_TO_MM, 2),
                    'w' => round($w * self::PT_TO_MM, 2),
                    'h' => round($h * self::PT_TO_MM, 2),
                ];
            }

            usort($images, fn (array $a, array $b): int => [$a['y'], $a['x']] <=> [$b['y'], $b['x']]);
            $pages[] = $images;
        }

        return $pages;
    }
}
