<?php

namespace App\Support;

/**
 * Montants en FCFA entiers, espace insécable comme séparateur de milliers (CLAUDE.md).
 */
final class Money
{
    public static function format(?int $amount, bool $withCurrency = false): string
    {
        if ($amount === null) {
            return '—';
        }

        return number_format($amount, 0, ',', "\u{00A0}").($withCurrency ? "\u{00A0}FCFA" : '');
    }
}
