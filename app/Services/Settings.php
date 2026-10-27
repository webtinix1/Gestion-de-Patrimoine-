<?php

namespace App\Services;

use App\Models\Setting;

/**
 * Paramètres de l'application (table settings), avec les valeurs par défaut de docs/06
 * tant que l'Église n'a pas répondu. Écran de paramétrage : E41.
 */
class Settings
{
    // Q10 : format du numéro patrimonial.
    public const string NUMBER_FORMAT = 'number_format';

    public const string NUMBER_PREFIX = 'number_prefix';

    // Q2 / RG-30 : seuil d'immobilisation en FCFA.
    public const string CAPITALIZATION_THRESHOLD = 'capitalization_threshold';

    // Q15 : identité de l'Église.
    public const string CHURCH_NAME = 'church_name';

    public const string CHURCH_ACRONYM = 'church_acronym';

    // RG-66 : documents.
    public const string DOCUMENT_MAX_SIZE_KB = 'document_max_size_kb';

    public const string FORMAT_GLOBAL = 'global';

    public const string FORMAT_YEARLY = 'yearly';

    public const string FORMAT_CATEGORY = 'category';

    /**
     * @var array<string, mixed>
     */
    public const array DEFAULTS = [
        self::NUMBER_FORMAT => self::FORMAT_GLOBAL,
        self::NUMBER_PREFIX => 'PAT',
        self::CAPITALIZATION_THRESHOLD => 100000,
        self::CHURCH_NAME => '[Nom de l\'Église]',
        self::CHURCH_ACRONYM => '[SIGLE]',
        self::DOCUMENT_MAX_SIZE_KB => 20480,
    ];

    /** @var array<string, mixed>|null */
    private ?array $cache = null;

    public function get(string $key): mixed
    {
        $this->cache ??= Setting::query()->pluck('value', 'key')->all();

        return $this->cache[$key] ?? self::DEFAULTS[$key] ?? null;
    }

    public function set(string $key, mixed $value): void
    {
        Setting::query()->updateOrCreate(['key' => $key], ['value' => $value, 'updated_by' => auth()->id()]);

        $this->cache = null;
    }

    public function string(string $key): string
    {
        $value = $this->get($key);

        return is_scalar($value) ? (string) $value : '';
    }

    public function int(string $key): int
    {
        $value = $this->get($key);

        return is_numeric($value) ? (int) $value : 0;
    }
}
