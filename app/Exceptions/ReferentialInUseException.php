<?php

namespace App\Exceptions;

use RuntimeException;

class ReferentialInUseException extends RuntimeException
{
    public static function make(): self
    {
        return new self('Cet élément est utilisé : il ne peut pas être supprimé. Désactivez-le à la place.');
    }
}
