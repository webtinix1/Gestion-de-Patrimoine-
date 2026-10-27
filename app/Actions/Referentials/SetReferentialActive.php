<?php

namespace App\Actions\Referentials;

use Illuminate\Database\Eloquent\Model;

/**
 * Active ou désactive un élément de référentiel. Un élément désactivé reste
 * dans l'historique mais n'est plus proposé pour les nouvelles saisies.
 */
class SetReferentialActive
{
    public function __invoke(Model $record, bool $active): void
    {
        $record->forceFill(['is_active' => $active])->save();
    }
}
