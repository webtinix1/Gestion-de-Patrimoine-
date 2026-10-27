<?php

namespace App\Actions\Acquisitions;

use App\Models\Acquisition;
use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * Seul un brouillon, qui n'a créé aucune fiche, se supprime. Les fichiers des justificatifs sont conservés.
 */
class DeleteAcquisition
{
    public function __invoke(Acquisition $acquisition): void
    {
        DB::transaction(function () use ($acquisition): void {
            $locked = Acquisition::query()->whereKey($acquisition->getKey())->lockForUpdate()->firstOrFail();

            if (! $locked->isDraft() || $locked->assets()->exists()) {
                throw new DomainException('Une acquisition validée, qui a créé des fiches, ne se supprime pas.');
            }

            $locked->documents()->detach();
            $locked->delete();
        });
    }
}
