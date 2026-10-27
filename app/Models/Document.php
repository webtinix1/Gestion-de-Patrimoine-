<?php

namespace App\Models;

use App\Support\FileSize;
use Database\Factories\DocumentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphToMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * RG-65 : document rattaché à un ou plusieurs objets. RG-66 : le fichier reste sur le disque privé.
 *
 * @property int $id
 * @property int $document_type_id
 * @property string $original_name
 * @property string $path
 * @property string $mime
 * @property int $size
 * @property int|null $uploaded_by
 * @property Carbon|null $created_at
 */
#[Fillable(['document_type_id', 'original_name', 'path', 'mime', 'size', 'uploaded_by'])]
class Document extends Model
{
    /** @use HasFactory<DocumentFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['size' => 'integer'];
    }

    /**
     * @return BelongsTo<DocumentType, $this>
     */
    public function type(): BelongsTo
    {
        return $this->belongsTo(DocumentType::class, 'document_type_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    /**
     * Biens rattachés, limités au périmètre de l'utilisateur connecté.
     *
     * @return MorphToMany<Asset, $this>
     */
    public function assets(): MorphToMany
    {
        return $this->morphedByMany(Asset::class, 'documentable')->withTimestamps();
    }

    /**
     * Acquisitions dont le document est un justificatif, limitées au périmètre de l'utilisateur.
     *
     * @return MorphToMany<Acquisition, $this>
     */
    public function acquisitions(): MorphToMany
    {
        return $this->morphedByMany(Acquisition::class, 'documentable')->withTimestamps();
    }

    public function humanSize(): string
    {
        return FileSize::format($this->size);
    }

    public function fileUrl(bool $download = false): string
    {
        return route('filament.admin.documents.file', ['document' => $this->getKey()] + ($download ? ['download' => 1] : []));
    }

    public function isAttached(): bool
    {
        return DB::table('documentables')->where('document_id', $this->getKey())->exists();
    }

    /**
     * RG-81 / RG-66 : un document est visible si au moins un objet rattaché l'est. Un document
     * détaché de tout n'est visible que par un utilisateur sans restriction de périmètre.
     *
     * @param  Builder<static>  $query
     */
    public function scopeVisible(Builder $query): void
    {
        $user = auth()->user();

        if (! $user instanceof User) {
            return;
        }

        $unrestricted = $user->perimeterSiteIds() === null && $user->perimeterDepartmentIds() === null;

        $query->where(function (Builder $query) use ($unrestricted): void {
            $query->whereHas('assets')->orWhereHas('acquisitions');

            if ($unrestricted) {
                $query->orWhereNotExists(
                    fn ($sub) => $sub->selectRaw('1')->from('documentables')->whereColumn('documentables.document_id', 'documents.id'),
                );
            }
        });
    }
}
