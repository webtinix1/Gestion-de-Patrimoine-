<?php

namespace App\Actions\Users;

/**
 * RG-85 : mot de passe provisoire lisible (sans caractères ambigus), conforme à la règle
 * de 8 caractères avec un chiffre, à communiquer en main propre.
 */
class GenerateTemporaryPassword
{
    private const string LETTERS = 'abcdefghjkmnpqrstuvwxyzABCDEFGHJKMNPQRSTUVWXYZ';

    private const string DIGITS = '23456789';

    public function __invoke(): string
    {
        $part = fn (int $length, string $alphabet): string => collect(range(1, $length))
            ->map(fn (): string => $alphabet[random_int(0, strlen($alphabet) - 1)])
            ->join('');

        return $part(3, self::LETTERS).random_int(2, 9).'-'.$part(3, self::LETTERS).'-'.$part(2, self::DIGITS);
    }
}
