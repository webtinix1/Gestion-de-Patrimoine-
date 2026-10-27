<?php

namespace App\Models;

use App\Models\Concerns\IsReferential;
use App\Models\Concerns\RecordsAuthors;
use Database\Factories\DocumentTypeFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * RG-65 : type de document, liste paramétrable.
 *
 * @property int $id
 * @property string $label
 * @property bool $is_active
 */
#[Fillable(['label', 'is_active'])]
class DocumentType extends Model
{
    /** @use HasFactory<DocumentTypeFactory> */
    use HasFactory, IsReferential, RecordsAuthors;

    protected $attributes = ['is_active' => true];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function usageRelations(): array
    {
        return [];
    }
}
