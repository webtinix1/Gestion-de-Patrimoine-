<?php

namespace App\Services;

/**
 * RG-66 : formats acceptés (PDF, images, Word, Excel) et taille maximale lue dans les paramètres.
 */
class DocumentRules
{
    public const string DISK = 'local';

    public const string DIRECTORY = 'documents';

    /**
     * @var list<string>
     */
    public const array MIME_TYPES = [
        'application/pdf',
        'image/jpeg',
        'image/png',
        'image/webp',
        'image/gif',
        'application/msword',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'application/vnd.ms-excel',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
    ];

    public function __construct(private Settings $settings) {}

    public function maxSizeKb(): int
    {
        return max(1, $this->settings->int(Settings::DOCUMENT_MAX_SIZE_KB));
    }

    /**
     * @return list<string>
     */
    public function fileRules(): array
    {
        return ['file', 'max:'.$this->maxSizeKb(), 'mimetypes:'.implode(',', self::MIME_TYPES)];
    }

    public static function isInline(string $mime): bool
    {
        return $mime === 'application/pdf' || str_starts_with($mime, 'image/');
    }
}
