<?php

namespace App\Services\FiscalYearClosing;

/**
 * RG-42 : un point affiché avant la clôture. « ok » : rien à confirmer ; « warning » : à confirmer ;
 * « unavailable » : vérification impossible tant que le module n'existe pas, à confirmer aussi.
 */
final readonly class AttentionPoint
{
    public const string OK = 'ok';

    public const string WARNING = 'warning';

    public const string UNAVAILABLE = 'unavailable';

    public function __construct(
        public string $key,
        public string $label,
        public string $severity,
    ) {}

    public function needsConfirmation(): bool
    {
        return $this->severity !== self::OK;
    }

    /**
     * @return array{key: string, label: string, severity: string}
     */
    public function toArray(): array
    {
        return ['key' => $this->key, 'label' => $this->label, 'severity' => $this->severity];
    }
}
