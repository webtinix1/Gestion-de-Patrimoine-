<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

/**
 * RG-96 : planche A4 3 × 8 ou rouleau ; la planche 2 × 7 est prévue par docs/03.
 */
enum LabelSheetLayout: string implements HasLabel
{
    case A4ThreeByEight = 'a4_3x8';
    case A4TwoBySeven = 'a4_2x7';
    case Roll = 'roll';

    public const float A4_WIDTH_MM = 210.0;

    public const float A4_HEIGHT_MM = 297.0;

    public function getLabel(): string
    {
        return match ($this) {
            self::A4ThreeByEight => 'Planche A4 · 3 × 8 (24 étiquettes)',
            self::A4TwoBySeven => 'Planche A4 · 2 × 7 (14 étiquettes)',
            self::Roll => 'Rouleau continu',
        };
    }

    public function isSheet(): bool
    {
        return $this !== self::Roll;
    }

    public function columns(): int
    {
        return match ($this) {
            self::A4ThreeByEight => 3,
            self::A4TwoBySeven => 2,
            self::Roll => 1,
        };
    }

    public function rows(): int
    {
        return match ($this) {
            self::A4ThreeByEight => 8,
            self::A4TwoBySeven => 7,
            self::Roll => 1,
        };
    }

    public function perPage(): int
    {
        return $this->columns() * $this->rows();
    }

    /**
     * Dimensions usuelles d'une étiquette de ce support, en mm (largeur, hauteur).
     *
     * @return array{0: float, 1: float}
     */
    public function defaultSize(): array
    {
        return match ($this) {
            self::A4ThreeByEight => [70.0, 37.0],
            self::A4TwoBySeven => [99.1, 38.1],
            self::Roll => [50.0, 25.0],
        };
    }
}
