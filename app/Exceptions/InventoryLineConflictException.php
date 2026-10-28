<?php

namespace App\Exceptions;

use App\Enums\InventoryLineResult;
use App\Models\User;
use Illuminate\Support\Carbon;
use RuntimeException;

/**
 * RG-47 : la ligne a été pointée par quelqu'un d'autre depuis son affichage. Rien n'est remplacé.
 */
class InventoryLineConflictException extends RuntimeException
{
    public function __construct(
        public readonly string $number,
        public readonly InventoryLineResult $result,
        public readonly ?User $checker,
        public readonly ?Carbon $checkedAt,
    ) {
        parent::__construct(sprintf(
            '%s a été pointé « %s » par %s à %s depuis l\'affichage de la liste. Votre saisie n\'a pas été enregistrée : vérifiez la ligne à jour, puis corrigez-la si besoin.',
            $number,
            $result->getLabel(),
            $checker?->fullName() ?? 'un autre poste',
            $checkedAt?->format('H:i') ?? '—',
        ));
    }
}
