<?php

namespace App\Services\Labels;

use App\Enums\LabelCodeType;
use App\Models\Asset;
use App\Support\Labels\CodeImage;
use Endroid\QrCode\Builder\Builder;
use Endroid\QrCode\ErrorCorrectionLevel;
use Endroid\QrCode\RoundBlockSizeMode;
use Endroid\QrCode\Writer\PngWriter;
use Endroid\QrCode\Writer\SvgWriter;
use InvalidArgumentException;
use Picqer\Barcode\BarcodeGenerator;
use Picqer\Barcode\BarcodeGeneratorPNG;
use Picqer\Barcode\BarcodeGeneratorSVG;

/**
 * Génère les codes en local (aucune ressource externe). PNG à 600 dpi pour le PDF, SVG pour l'aperçu.
 */
class LabelCodeRenderer
{
    public const int DPI = 600;

    /**
     * RG-95 : le code ne contient que le numéro patrimonial, jamais une adresse ni une autre donnée.
     */
    public function payload(string $number): string
    {
        $number = trim($number);

        if ($number === '' || ! preg_match('/^[A-Za-z0-9-]+$/', $number)) {
            throw new InvalidArgumentException('Un numéro patrimonial ne contient que des lettres, des chiffres et des tirets.');
        }

        return $number;
    }

    public function forAsset(Asset $asset, LabelCodeType $type, float $maxWidthMm, float $maxHeightMm, bool $vector = false): CodeImage
    {
        return $this->render($type, $asset->number, $maxWidthMm, $maxHeightMm, $vector);
    }

    /**
     * Le QR code est carré (le plus grand carré qui tient dans la zone) ; le Code 128 prend la largeur
     * entière possible avec des barres d'un nombre entier de pixels (plus net à l'impression), zone
     * de silence de 10 modules comprise.
     */
    public function render(LabelCodeType $type, string $number, float $maxWidthMm, float $maxHeightMm, bool $vector = false): CodeImage
    {
        $payload = $this->payload($number);

        return match ($type) {
            LabelCodeType::Qr => $this->qr($payload, min($maxWidthMm, $maxHeightMm), $vector),
            LabelCodeType::Code128 => $this->code128($payload, $maxWidthMm, $maxHeightMm, $vector),
        };
    }

    private function qr(string $payload, float $sizeMm, bool $vector): CodeImage
    {
        $builder = new Builder(
            writer: $vector ? new SvgWriter : new PngWriter,
            data: $payload,
            errorCorrectionLevel: ErrorCorrectionLevel::Medium,
            size: max(21, $this->px($sizeMm)),
            margin: 0,
            roundBlockSizeMode: RoundBlockSizeMode::Enlarge,
        );

        return new CodeImage($builder->build()->getDataUri(), $sizeMm, $sizeMm);
    }

    private function code128(string $payload, float $maxWidthMm, float $maxHeightMm, bool $vector): CodeImage
    {
        $modules = $this->modules($payload);
        $factor = 2;

        for ($candidate = 8; $candidate >= 2; $candidate--) {
            $moduleMm = $this->mm($candidate);

            if (($modules + 20) * $moduleMm <= $maxWidthMm) {
                $factor = $candidate;

                break;
            }
        }

        $widthMm = $modules * $this->mm($factor);
        $heightPx = max(10, $this->px($maxHeightMm));

        if ($vector) {
            $svg = (new BarcodeGeneratorSVG)->getBarcode($payload, BarcodeGenerator::TYPE_CODE_128, $factor, $heightPx);

            return new CodeImage('data:image/svg+xml;base64,'.base64_encode($svg), $widthMm, $maxHeightMm);
        }

        $png = (new BarcodeGeneratorPNG)->getBarcode($payload, BarcodeGenerator::TYPE_CODE_128, $factor, $heightPx);

        return new CodeImage('data:image/png;base64,'.base64_encode($png), $widthMm, $maxHeightMm);
    }

    private function modules(string $payload): int
    {
        $png = (new BarcodeGeneratorPNG)->getBarcode($payload, BarcodeGenerator::TYPE_CODE_128, 1, 1);
        $size = getimagesizefromstring($png);

        return $size === false ? 0 : $size[0];
    }

    private function px(float $mm): int
    {
        return (int) round($mm / 25.4 * self::DPI);
    }

    private function mm(int $px): float
    {
        return $px / self::DPI * 25.4;
    }
}
