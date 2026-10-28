<?php

namespace App\Filament\Support;

use App\Models\DocumentType;
use App\Services\DocumentRules;
use App\Support\FileSize;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;

/**
 * Champs d'ajout de documents partagés par la fiche d'un bien et l'écran E28 (RG-66).
 */
final class DocumentFields
{
    public static function files(): FileUpload
    {
        $maxKb = app(DocumentRules::class)->maxSizeKb();

        return FileUpload::make('files')
            ->label('Fichiers')
            ->multiple()
            ->required()
            ->storeFiles(false)
            ->disk(DocumentRules::DISK)
            ->acceptedFileTypes(DocumentRules::MIME_TYPES)
            ->maxSize($maxKb)
            ->helperText('PDF, images, Word ou Excel. '.FileSize::format($maxKb * 1024).' au maximum par fichier.');
    }

    public static function type(): Select
    {
        return Select::make('document_type_id')
            ->label('Type de document')
            ->options(fn (): array => DocumentType::query()->active()->orderBy('label')->pluck('label', 'id')->all())
            ->required()
            ->native(false);
    }
}
