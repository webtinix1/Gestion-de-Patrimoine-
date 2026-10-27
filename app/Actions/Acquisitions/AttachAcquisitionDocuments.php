<?php

namespace App\Actions\Acquisitions;

use App\Models\Acquisition;
use App\Models\Document;
use App\Models\DocumentType;
use App\Services\DocumentRules;
use DomainException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Throwable;

/**
 * RG-65 / RG-66 : justificatifs d'une acquisition (facture, bon de livraison, PV), stockés sur le disque privé.
 * Ils ne s'ajoutent qu'à un brouillon : après validation, la case « Rattacher aussi aux biens créés » les propage.
 */
class AttachAcquisitionDocuments
{
    public function __construct(private DocumentRules $rules) {}

    /**
     * @param  iterable<UploadedFile>  $files
     * @return Collection<int, Document>
     */
    public function __invoke(iterable $files, DocumentType $type, Acquisition $acquisition): Collection
    {
        if (! $acquisition->isDraft()) {
            throw new DomainException('Une acquisition validée n\'est plus modifiable : aucun justificatif ne peut y être ajouté.');
        }

        $files = collect($files)->values();

        foreach ($files as $file) {
            Validator::make(['file' => $file], ['file' => $this->rules->fileRules()])->validate();
        }

        $stored = [];

        try {
            return DB::transaction(fn () => $files->map(function (UploadedFile $file) use ($type, $acquisition, &$stored): Document {
                $path = (string) $file->store(DocumentRules::DIRECTORY, DocumentRules::DISK);
                $stored[] = $path;

                $document = Document::query()->create([
                    'document_type_id' => $type->getKey(),
                    'original_name' => $file->getClientOriginalName(),
                    'path' => $path,
                    'mime' => $file->getMimeType() ?? 'application/octet-stream',
                    'size' => (int) $file->getSize(),
                    'uploaded_by' => auth()->id(),
                ]);

                $document->acquisitions()->attach($acquisition->getKey());

                activity('documents')
                    ->performedOn($acquisition)
                    ->event('document_attached')
                    ->withProperties(['document' => $document->original_name, 'type' => $type->label])
                    ->log('Ajout d\'un justificatif');

                return $document;
            }));
        } catch (Throwable $exception) {
            Storage::disk(DocumentRules::DISK)->delete($stored);

            throw $exception;
        }
    }
}
