<?php

namespace App\Services\Labels;

use App\Enums\LabelCodeType;
use App\Models\Asset;
use App\Support\Labels\CodeImage;
use DomainException;
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
 * Génère les codes en local (aucune ressource externe), PNG pour le PDF, SVG pour l'aperçu.
 * Chaque image n'est calculée qu'une fois par numéro et par taille, quel que soit le nombre de copies.
 */
class LabelCodeRenderer
{
    /** Zone de silence du QR code, en modules (norme : 4). */
    public const int QR_QUIET_MODULES = 4;

    /** Zone de silence du Code 128, en modules (norme : 10). */
    public const int BARCODE_QUIET_MODULES = 10;

    /** Résolution visée pour le PNG du QR code. */
    private const int QR_DPI = 300;

    /**
     * Largeurs de module du Code 128, en mm : multiples de 0,125 mm (un point d'une imprimante thermique de 203 dpi).
     *
     * @var list<float>
     */
    private const array BARCODE_MODULES_MM = [0.5, 0.375, 0.25];

    /** Pixels par 0,25 mm dans l'image du Code 128 (barres nettes et à l'échelle exacte). */
    private const int BARCODE_PX_PER_QUARTER_MM = 8;

    /** @var array<string, CodeImage> */
    private array $cache = [];

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
     * Le QR code est carré (le plus grand carré qui tient dans la zone, zone de silence comprise).
     * Le Code 128 prend le plus grand module de 0,5, 0,375 ou 0,25 mm qui tient avec 10 modules de silence
     * de chaque côté ; sinon le code est refusé (DomainException) plutôt que réduit au point d'être illisible.
     */
    public function render(LabelCodeType $type, string $number, float $maxWidthMm, float $maxHeightMm, bool $vector = false): CodeImage
    {
        $payload = $this->payload($number);
        $key = implode('|', [$type->value, $payload, round($maxWidthMm, 3), round($maxHeightMm, 3), $vector ? 'v' : 'p']);

        return $this->cache[$key] ??= match ($type) {
            LabelCodeType::Qr => $this->qr($payload, min($maxWidthMm, $maxHeightMm), $vector),
            LabelCodeType::Code128 => $this->code128($payload, $maxWidthMm, $maxHeightMm, $vector),
        };
    }

    private function qr(string $payload, float $sizeMm, bool $vector): CodeImage
    {
        $modules = $this->qrModules($payload);
        $total = $modules + 2 * self::QR_QUIET_MODULES;
        $pixelsPerModule = max(3, (int) round($sizeMm / 25.4 * self::QR_DPI / $total));

        $builder = new Builder(
            writer: $vector ? new SvgWriter : new PngWriter,
            data: $payload,
            errorCorrectionLevel: ErrorCorrectionLevel::Medium,
            size: $modules * $pixelsPerModule,
            margin: self::QR_QUIET_MODULES * $pixelsPerModule,
            roundBlockSizeMode: RoundBlockSizeMode::Margin,
        );

        return new CodeImage($builder->build()->getDataUri(), $sizeMm, $sizeMm);
    }

    private function qrModules(string $payload): int
    {
        $probe = new Builder(
            writer: new SvgWriter,
            data: $payload,
            errorCorrectionLevel: ErrorCorrectionLevel::Medium,
            size: 100,
            margin: 0,
        );

        return $probe->build()->getMatrix()->getBlockCount();
    }

    private function code128(string $payload, float $maxWidthMm, float $maxHeightMm, bool $vector): CodeImage
    {
        $modules = $this->modules($payload);
        $moduleMm = null;

        foreach (self::BARCODE_MODULES_MM as $candidate) {
            if (($modules + 2 * self::BARCODE_QUIET_MODULES) * $candidate <= $maxWidthMm + 0.0001) {
                $moduleMm = $candidate;

                break;
            }
        }

        if ($moduleMm === null) {
            throw new DomainException("Le code-barres de {$payload} ne tient pas sur cette étiquette avec sa zone de silence : choisissez un QR code ou une étiquette plus large.");
        }

        $factor = (int) round($moduleMm / 0.25 * self::BARCODE_PX_PER_QUARTER_MM);
        $heightPx = max(10, (int) round($maxHeightMm / 0.25 * self::BARCODE_PX_PER_QUARTER_MM));
        $widthMm = $modules * $moduleMm;

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
}
