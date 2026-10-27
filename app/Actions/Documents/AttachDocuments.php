<?php

namespace App\Actions\Documents;

use App\Models\Asset;
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
 * RG-65 / RG-66 : enregistre les fichiers sur le disque privé et les rattache aux biens.
 * RG-70 : chaque ajout est inscrit au journal, avec le bien comme sujet.
 */
class AttachDocuments
{
    public function __construct(private DocumentRules $rules) {}

    /**
     * @param  iterable<UploadedFile>  $files
     * @param  iterable<Asset>  $assets
     * @return Collection<int, Document>
     */
    public function __invoke(iterable $files, DocumentType $type, iterable $assets): Collection
    {
        $assets = collect($assets)->values();
        $files = collect($files)->values();

        if ($assets->isEmpty()) {
            throw new DomainException('Un document doit être rattaché à au moins un bien.');
        }

        if ($assets->contains(fn (Asset $asset): bool => $asset->isDisposed())) {
            throw new DomainException('Un bien sorti du patrimoine est en lecture seule : aucun document ne peut y être ajouté.');
        }

        foreach ($files as $file) {
            Validator::make(['file' => $file], ['file' => $this->rules->fileRules()])->validate();
        }

        $stored = [];

        try {
            return DB::transaction(fn () => $files->map(function (UploadedFile $file) use ($type, $assets, &$stored): Document {
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

                $document->assets()->attach($assets->map->getKey()->all());

                foreach ($assets as $asset) {
                    activity('documents')
                        ->performedOn($asset)
                        ->event('document_attached')
                        ->withProperties(['document' => $document->original_name, 'type' => $type->label])
                        ->log('Ajout d\'un document');
                }

                return $document;
            }));
        } catch (Throwable $exception) {
            Storage::disk(DocumentRules::DISK)->delete($stored);

            throw $exception;
        }
    }
}
