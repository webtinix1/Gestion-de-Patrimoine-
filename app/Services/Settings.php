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

    // Q1 / RG-32 : prorata temporis (seul le mode « jours sur 360 » est défini à ce jour).
    public const string DEPRECIATION_PRORATA = 'depreciation_prorata';

    public const string PRORATA_DAYS_360 = 'days_360';

    // Q4 / RG-35 : méthode dégressive, désactivée par défaut, et ses coefficients.
    public const string DECLINING_ENABLED = 'declining_enabled';

    public const string DECLINING_COEFFICIENT_3_4 = 'declining_coefficient_3_4';

    public const string DECLINING_COEFFICIENT_5_6 = 'declining_coefficient_5_6';

    public const string DECLINING_COEFFICIENT_OVER_6 = 'declining_coefficient_over_6';

    // RG-66 : documents.
    public const string DOCUMENT_MAX_SIZE_KB = 'document_max_size_kb';

    // RG-90 : délais des alertes, paramétrables.
    public const string ALERT_LOAN_DUE_DAYS = 'alert_loan_due_days';

    public const string ALERT_MAINTENANCE_DAYS = 'alert_maintenance_days';

    public const string ALERT_INVENTORY_DAYS = 'alert_inventory_days';

    public const string ALERT_DEPRECIATION_MONTHS = 'alert_depreciation_months';

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
        self::ALERT_LOAN_DUE_DAYS => 3,
        self::ALERT_MAINTENANCE_DAYS => 15,
        self::ALERT_INVENTORY_DAYS => 7,
        self::ALERT_DEPRECIATION_MONTHS => 3,
        self::DEPRECIATION_PRORATA => self::PRORATA_DAYS_360,
        self::DECLINING_ENABLED => false,
        self::DECLINING_COEFFICIENT_3_4 => '1.5',
        self::DECLINING_COEFFICIENT_5_6 => '2',
        self::DECLINING_COEFFICIENT_OVER_6 => '2.5',
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

    public function bool(string $key): bool
    {
        return filter_var($this->get($key), FILTER_VALIDATE_BOOL);
    }
}
