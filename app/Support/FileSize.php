<?php

namespace App\Support;

/**
 * Taille de fichier lisible en français : « 512 Ko », « 1,5 Mo ».
 */
final class FileSize
{
    public static function format(int $bytes): string
    {
        if ($bytes < 1024) {
            return $bytes.' o';
        }

        if ($bytes < 1024 * 1024) {
            return self::round($bytes / 1024).' Ko';
        }

        return self::round($bytes / (1024 * 1024)).' Mo';
    }

    private static function round(float $value): string
    {
        return rtrim(rtrim(number_format($value, 1, ',', ' '), '0'), ',');
    }
}
