<?php

namespace App\Filament;

/**
 * Palettes du thème (docs/04-ecrans-filament.md, « Détails de style »).
 *
 * Elles sont fixées à la main plutôt que générées depuis une seule couleur :
 * la génération automatique de Filament produit des nuances trop vives pour
 * le bleu pétrole et les boutons ne prennent plus la couleur primaire.
 * Primaire : nuance 600. Statuts : fond en 50, texte en 600.
 */
final class Theme
{
    public const string PRIMARY = '#0B5D6B';

    public const string ACCENT = '#F2B544';

    /**
     * @return array<string, array<int, string>>
     */
    public static function colors(): array
    {
        return [
            'primary' => [
                50 => '#E7F2F4', 100 => '#CFE5E9', 200 => '#A3CDD4', 300 => '#6FAEB9', 400 => '#3A8D9A',
                500 => '#17727F', 600 => self::PRIMARY, 700 => '#094C58', 800 => '#083E48', 900 => '#06313A', 950 => '#041F25',
            ],
            'accent' => [
                50 => '#FEF8EC', 100 => '#FCEFD2', 200 => '#F9DFA5', 300 => '#F6CC76', 400 => self::ACCENT,
                500 => '#E09B1F', 600 => '#BD7C12', 700 => '#965E10', 800 => '#794B13', 900 => '#633E13', 950 => '#392106',
            ],
            'gray' => [
                50 => '#F4F6F8', 100 => '#ECEEF1', 200 => '#E1E4E8', 300 => '#D7DBE1', 400 => '#A3A8B0',
                500 => '#7D8189', 600 => '#686868', 700 => '#4A4D52', 800 => '#2E2E2E', 900 => '#262626', 950 => '#171717',
            ],
            'success' => [
                50 => '#E4F2EA', 100 => '#C9E5D4', 200 => '#9DCDB0', 300 => '#6BB088', 400 => '#3F9264',
                500 => '#2A7F51', 600 => '#1E6E43', 700 => '#185A37', 800 => '#14482D', 900 => '#103B25', 950 => '#0A2416',
            ],
            'warning' => [
                50 => '#FBF0D9', 100 => '#F7E1B3', 200 => '#F0C978', 300 => '#E5AE45', 400 => '#CC9224',
                500 => '#A87310', 600 => '#8A5A00', 700 => '#704900', 800 => '#5A3B00', 900 => '#4A3000', 950 => '#2C1C00',
            ],
            'danger' => [
                50 => '#FBE7E5', 100 => '#F7CFCB', 200 => '#EEA59E', 300 => '#E2756B', 400 => '#D04B3F',
                500 => '#BC3427', 600 => '#A8261B', 700 => '#8A1F16', 800 => '#711A13', 900 => '#5D1610', 950 => '#380C08',
            ],
            'info' => [
                50 => '#E6EBF8', 100 => '#CDD7F1', 200 => '#A2B4E3', 300 => '#758ED2', 400 => '#536FBF',
                500 => '#3D59AE', 600 => '#2F4A9E', 700 => '#263C82', 800 => '#1F316A', 900 => '#1A2957', 950 => '#101935',
            ],
        ];
    }
}
