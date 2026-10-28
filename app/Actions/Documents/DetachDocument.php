<?php

namespace App\Actions\Documents;

use App\Models\Asset;
use App\Models\Document;
use DomainException;

/**
 * Détacher retire le lien avec le bien, jamais le fichier. RG-70 : inscrit au journal.
 */
class DetachDocument
{
    public function __invoke(Document $document, Asset $asset): void
    {
        if ($asset->isDisposed()) {
            throw new DomainException('Un bien sorti du patrimoine est en lecture seule : aucun document ne peut en être détaché.');
        }

        $document->assets()->detach($asset->getKey());

        activity('documents')
            ->performedOn($asset)
            ->event('document_detached')
            ->withProperties(['document' => $document->original_name, 'type' => $document->type->label])
            ->log('Retrait d\'un document');
    }
}
