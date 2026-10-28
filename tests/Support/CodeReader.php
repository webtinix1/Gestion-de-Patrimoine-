<?php

namespace Tests\Support;

use chillerlan\QRCode\QRCode;
use chillerlan\QRCode\QROptions;
use Picqer\Barcode\Types\TypeCode128;

/**
 * Relit les codes comme le ferait une douchette : décode le QR code et le Code 128 à partir de l'image.
 */
final class CodeReader
{
    public static function bytes(string $dataUri): string
    {
        return (string) base64_decode(substr($dataUri, (int) strpos($dataUri, ',') + 1));
    }

    public static function qr(string $png): string
    {
        return (string) (new QRCode(new QROptions))->readFromBlob($png);
    }

    /**
     * @return array{text: string, checksum_ok: bool, module_px: int, modules: int}
     */
    public static function code128(string $png): array
    {
        $table = (fn () => $this->conversionTable)->call(new TypeCode128);
        $image = imagecreatefromstring($png);
        $width = imagesx($image);
        $y = (int) (imagesy($image) / 2);
        $runs = [];
        $previous = null;
        $length = 0;

        for ($x = 0; $x < $width; $x++) {
            $color = imagecolorsforindex($image, imagecolorat($image, $x, $y));
            $black = $color['alpha'] < 64 && $color['red'] < 128;
            $previous ??= $black;

            if ($black === $previous) {
                $length++;
            } else {
                $runs[] = [$previous, $length];
                $previous = $black;
                $length = 1;
            }
        }

        $runs[] = [$previous, $length];

        while ($runs !== [] && ! $runs[0][0]) {
            array_shift($runs);
        }

        while ($runs !== [] && ! end($runs)[0]) {
            array_pop($runs);
        }

        $min = min(array_column($runs, 1));
        $widths = array_map(fn (array $run): int => (int) round($run[1] / $min), $runs);
        $values = [];

        for ($i = 0; $i + 6 <= count($widths); $i += 6) {
            if (count($widths) - $i === 7) {
                break;
            }

            $found = array_search(implode('', array_slice($widths, $i, 6)), $table, true);
            $values[] = $found === false ? -1 : $found;
        }

        $start = $values[0] ?? 0;
        $set = [103 => 'A', 104 => 'B', 105 => 'C'][$start] ?? '?';
        $data = array_slice($values, 1, -1);
        $check = $values === [] ? -1 : end($values);

        $text = '';

        foreach ($data as $value) {
            if ($set === 'C') {
                if ($value === 100) {
                    $set = 'B';
                } elseif ($value === 101) {
                    $set = 'A';
                } else {
                    $text .= str_pad((string) $value, 2, '0', STR_PAD_LEFT);
                }

                continue;
            }

            if ($value === 99) {
                $set = 'C';
            } elseif ($set === 'B' && $value === 101) {
                $set = 'A';
            } elseif ($set === 'A' && $value === 100) {
                $set = 'B';
            } else {
                $text .= chr($value + 32);
            }
        }

        return ['text' => $text, 'checksum_ok' => self::checksum($start, $data) === $check, 'module_px' => $min, 'modules' => array_sum($widths)];
    }

    /**
     * @param  list<int>  $data
     */
    private static function checksum(int $start, array $data): int
    {
        $sum = $start;

        foreach ($data as $index => $value) {
            $sum += $value * ($index + 1);
        }

        return $sum % 103;
    }
}
