<?php

namespace App\Services\Inventories;

use App\Models\InventoryLine;

/**
 * Résultat d'une lecture à la douchette, affiché sous le champ de saisie (E20).
 */
final readonly class ScanOutcome
{
    public const string RECORDED = 'recorded';

    public const string OUT_OF_SCOPE = 'out_of_scope';

    public const string ALREADY = 'already';

    public const string CONFLICT = 'conflict';

    public const string UNKNOWN = 'unknown';

    public function __construct(
        public string $status,
        public string $code,
        public string $message,
        public ?InventoryLine $line = null,
    ) {}

    public function recorded(): bool
    {
        return in_array($this->status, [self::RECORDED, self::OUT_OF_SCOPE], true);
    }

    /** Couleur du bandeau : succès, avertissement ou erreur. */
    public function tone(): string
    {
        return match ($this->status) {
            self::RECORDED => 'success',
            self::ALREADY => 'info',
            self::OUT_OF_SCOPE, self::CONFLICT => 'warning',
            default => 'danger',
        };
    }
}
