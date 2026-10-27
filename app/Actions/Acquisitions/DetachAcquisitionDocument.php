<?php

namespace App\Actions\Acquisitions;

use App\Models\Acquisition;
use App\Models\Document;
use DomainException;

/**
 * Détacher retire le lien avec le brouillon, jamais le fichier. RG-70 : inscrit au journal.
 */
class DetachAcquisitionDocument
{
    public function __invoke(Document $document, Acquisition $acquisition): void
    {
        if (! $acquisition->isDraft()) {
            throw new DomainException('Une acquisition validée n\'est plus modifiable : aucun justificatif ne peut en être détaché.');
        }

        $document->acquisitions()->detach($acquisition->getKey());

        activity('documents')
            ->performedOn($acquisition)
            ->event('document_detached')
            ->withProperties(['document' => $document->original_name, 'type' => $document->type->label])
            ->log('Retrait d\'un justificatif');
    }
}
