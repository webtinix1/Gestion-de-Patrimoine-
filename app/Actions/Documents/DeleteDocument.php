<?php

namespace App\Actions\Documents;

use App\Models\Document;
use App\Services\DocumentRules;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Prudence : un document encore rattaché à un objet ne se supprime pas, il se détache d'abord.
 */
class DeleteDocument
{
    public function __invoke(Document $document): void
    {
        if ($document->isAttached()) {
            throw new DomainException('Ce document est encore rattaché à un objet : détachez-le d\'abord.');
        }

        DB::transaction(function () use ($document): void {
            $document->delete();

            activity('documents')
                ->event('document_deleted')
                ->withProperties(['document' => $document->original_name])
                ->log('Suppression d\'un document non rattaché');
        });

        Storage::disk(DocumentRules::DISK)->delete($document->path);
    }
}
