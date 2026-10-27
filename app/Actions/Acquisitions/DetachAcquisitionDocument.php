<?php

namespace App\Actions\Acquisitions;

use App\Models\Acquisition;
use App\Models\Document;
use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * Détacher retire le lien avec le brouillon, jamais le fichier. RG-70 : inscrit au journal.
 * L'état de l'acquisition est relu sous verrou.
 */
class DetachAcquisitionDocument
{
    public function __invoke(Document $document, Acquisition $acquisition): void
    {
        DB::transaction(function () use ($document, $acquisition): void {
            $locked = Acquisition::query()->whereKey($acquisition->getKey())->lockForUpdate()->firstOrFail();

            if (! $locked->isDraft()) {
                throw new DomainException('Une acquisition validée n\'est plus modifiable : aucun justificatif ne peut en être détaché.');
            }

            $document->acquisitions()->detach($locked->getKey());

            activity('documents')
                ->performedOn($locked)
                ->event('document_detached')
                ->withProperties(['document' => $document->original_name, 'type' => $document->type->label])
                ->log('Retrait d\'un justificatif');
        });
    }
}
