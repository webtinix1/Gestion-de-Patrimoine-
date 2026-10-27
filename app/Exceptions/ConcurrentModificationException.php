<?php

namespace App\Exceptions;

use App\Models\User;
use Illuminate\Support\Carbon;
use RuntimeException;

/**
 * RG-86 : la fiche a été enregistrée par quelqu'un d'autre depuis son ouverture.
 */
class ConcurrentModificationException extends RuntimeException
{
    public function __construct(public readonly ?User $editor, public readonly ?Carbon $savedAt)
    {
        parent::__construct(sprintf(
            'Cette fiche a été enregistrée par %s à %s depuis son ouverture. Rechargez la page pour voir ses modifications avant de saisir les vôtres.',
            $editor?->fullName() ?? 'un autre utilisateur',
            $savedAt?->format('H:i') ?? '—',
        ));
    }
}
