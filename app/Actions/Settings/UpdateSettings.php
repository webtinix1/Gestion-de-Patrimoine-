<?php

namespace App\Actions\Settings;

use App\Http\Middleware\ApplySessionLifetime;
use App\Models\Asset;
use App\Models\Setting;
use App\Services\Settings;
use App\Services\System\EnvironmentCheck;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * E41 : enregistre les paramètres de l'application (identité, numérotation, alertes, sécurité, documents).
 * Seules les clés présentes dans `$data` sont modifiées. Tout est revalidé ici, quoi que le formulaire ait
 * contrôlé. Les valeurs changées sont journalisées avec leur état avant et après (RG-70) ; aucune valeur
 * secrète n'existe dans ces paramètres. Le seuil d'immobilisation et le dégressif relèvent de E32.
 */
class UpdateSettings
{
    /**
     * Bornes des délais d'alerte (RG-90) : [minimum, maximum].
     *
     * @var array<string, array{0: int, 1: int}>
     */
    public const array ALERT_BOUNDS = [
        Settings::ALERT_LOAN_DUE_DAYS => [0, 60],
        Settings::ALERT_MAINTENANCE_DAYS => [1, 365],
        Settings::ALERT_INVENTORY_DAYS => [0, 60],
        Settings::ALERT_DEPRECIATION_MONTHS => [1, 24],
    ];

    /** RG-66 : taille minimale et plafond absolu d'un document, en Ko (1 Mo à 100 Mo). */
    public const int DOCUMENT_MIN_KB = 1024;

    public const int DOCUMENT_CEILING_KB = 102400;

    public const int NAME_MAX = 120;

    public const int ACRONYM_MAX = 12;

    /**
     * @var list<string>
     */
    public const array KEYS = [
        Settings::CHURCH_NAME, Settings::CHURCH_ACRONYM, Settings::NUMBER_FORMAT, Settings::NUMBER_PREFIX,
        Settings::ALERT_LOAN_DUE_DAYS, Settings::ALERT_MAINTENANCE_DAYS, Settings::ALERT_INVENTORY_DAYS, Settings::ALERT_DEPRECIATION_MONTHS,
        Settings::SESSION_LIFETIME_MINUTES, Settings::DOCUMENT_MAX_SIZE_KB,
    ];

    public function __construct(private Settings $settings, private EnvironmentCheck $environment) {}

    /**
     * RG-02, RG-04 : dès qu'un bien existe (ou qu'un compteur a avancé), format et préfixe sont figés.
     */
    public static function numberingLocked(): bool
    {
        return Asset::query()->withoutGlobalScopes()->exists()
            || DB::table('asset_number_sequences')->where('last_value', '>', 0)->exists();
    }

    /**
     * Valeur en vigueur : la durée de session vient du fichier .env tant qu'aucune valeur n'est enregistrée.
     */
    public function current(string $key): mixed
    {
        if ($key === Settings::SESSION_LIFETIME_MINUTES && ! $this->settings->has($key)) {
            // Un SESSION_LIFETIME du .env hors bornes est ramené dans les bornes pour l'affichage et la validation.
            return max(ApplySessionLifetime::MIN_MINUTES, min(ApplySessionLifetime::MAX_MINUTES, (int) config('session.lifetime')));
        }

        return $this->settings->get($key);
    }

    /**
     * Plus grand document acceptable, en Ko : limite de PHP si elle est plus basse que le plafond.
     */
    public function documentMaxKb(): int
    {
        return max(self::DOCUMENT_MIN_KB, min(self::DOCUMENT_CEILING_KB, $this->environment->uploadLimitKb() ?? self::DOCUMENT_CEILING_KB));
    }

    /**
     * @param  array<string, mixed>  $data  valeurs à enregistrer (seules les clés présentes sont modifiées)
     * @param  array<string, mixed>  $original  valeurs chargées par l'écran : si l'une a changé depuis (autre administrateur), l'enregistrement est refusé
     */
    public function __invoke(array $data, array $original = []): void
    {
        Gate::authorize('update', Setting::class);

        // Verrou sur les compteurs de numéros : le test « des biens existent » et l'écriture du format ne se croisent pas avec une création de bien.
        DB::transaction(function () use ($data, $original): void {
            DB::table('asset_number_sequences')->lockForUpdate()->get();
            $this->settings->refresh();

            $this->apply($data, $original);
        });
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>  $original
     */
    private function apply(array $data, array $original): void
    {
        $values = [];
        $errors = [];

        foreach ([Settings::CHURCH_NAME => ['Le nom de l\'Église', self::NAME_MAX], Settings::CHURCH_ACRONYM => ['Le sigle', self::ACRONYM_MAX]] as $key => [$label, $max]) {
            if (! array_key_exists($key, $data)) {
                continue;
            }

            $text = is_string($data[$key]) ? trim(preg_replace('/\s+/u', ' ', $data[$key]) ?? '') : '';

            if ($text === '' || mb_strlen($text) > $max) {
                $errors[$key] = "{$label} est obligatoire ({$max} caractères au plus).";
            } else {
                $values[$key] = $text;
            }
        }

        $this->numbering($data, $values, $errors);

        foreach (self::ALERT_BOUNDS as $key => [$min, $max]) {
            if (array_key_exists($key, $data)) {
                $this->integer($key, $data[$key], $min, $max, $values, $errors, 'Le délai');
            }
        }

        if (array_key_exists(Settings::SESSION_LIFETIME_MINUTES, $data)) {
            $this->integer(Settings::SESSION_LIFETIME_MINUTES, $data[Settings::SESSION_LIFETIME_MINUTES], ApplySessionLifetime::MIN_MINUTES, ApplySessionLifetime::MAX_MINUTES, $values, $errors, 'La durée d\'inactivité');
        }

        if (array_key_exists(Settings::DOCUMENT_MAX_SIZE_KB, $data)) {
            $this->documentSize($data[Settings::DOCUMENT_MAX_SIZE_KB], $values, $errors);
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        $conflicts = [];

        foreach ($values as $key => $value) {
            if (array_key_exists($key, $original) && $original[$key] !== $this->current($key) && $this->current($key) !== $value) {
                $conflicts[$key] = 'Ce réglage a été modifié par un autre administrateur depuis l\'ouverture de l\'écran. Rechargez la page avant de le changer.';
            }
        }

        if ($conflicts !== []) {
            throw ValidationException::withMessages($conflicts);
        }

        $old = [];
        $new = [];

        foreach ($values as $key => $value) {
            $before = $this->current($key);

            if ($before !== $value) {
                $old[$key] = $before;
                $new[$key] = $value;
                $this->settings->set($key, $value);
            }
        }

        if ($new !== []) {
            // RG-70 : modification des paramètres de l'application.
            activity('parametres')
                ->event('settings_updated')
                ->withProperties(['old' => $old, 'attributes' => $new])
                ->log('Modification des paramètres de l\'application');
        }
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>  $values
     * @param  array<string, string>  $errors
     */
    private function numbering(array $data, array &$values, array &$errors): void
    {
        if (array_key_exists(Settings::NUMBER_FORMAT, $data)) {
            $format = $data[Settings::NUMBER_FORMAT];

            if (! in_array($format, [Settings::FORMAT_GLOBAL, Settings::FORMAT_YEARLY, Settings::FORMAT_CATEGORY], true)) {
                $errors[Settings::NUMBER_FORMAT] = 'Format du numéro inconnu.';
            } else {
                $values[Settings::NUMBER_FORMAT] = $format;
            }
        }

        if (array_key_exists(Settings::NUMBER_PREFIX, $data)) {
            $prefix = is_string($data[Settings::NUMBER_PREFIX]) ? mb_strtoupper(trim($data[Settings::NUMBER_PREFIX])) : '';

            if (preg_match('/^[A-Z0-9]{2,6}$/', $prefix) !== 1) {
                $errors[Settings::NUMBER_PREFIX] = 'Le préfixe compte de 2 à 6 lettres (sans accent) ou chiffres.';
            } else {
                $values[Settings::NUMBER_PREFIX] = $prefix;
            }
        }

        // RG-02, RG-04, Q10 : un numéro attribué ne change jamais et n'est jamais réutilisé ; on ne touche donc
        // plus au format ni au préfixe une fois le premier bien créé.
        foreach ([Settings::NUMBER_FORMAT, Settings::NUMBER_PREFIX] as $key) {
            if (isset($values[$key]) && $values[$key] !== $this->current($key) && self::numberingLocked()) {
                $errors[$key] = 'Le format et le préfixe du numéro ne peuvent plus changer : des biens ont déjà reçu un numéro (RG-04).';
                unset($values[$key]);
            }
        }
    }

    /**
     * @param  array<string, mixed>  $values
     * @param  array<string, string>  $errors
     */
    private function integer(string $key, mixed $input, int $min, int $max, array &$values, array &$errors, string $label): void
    {
        $number = is_int($input) ? $input : (is_string($input) && preg_match('/^\d{1,6}$/', trim($input)) === 1 ? (int) trim($input) : null);

        if ($number === null || $number < $min || $number > $max) {
            $errors[$key] = "{$label} doit être un nombre entier entre {$min} et {$max}.";

            return;
        }

        $values[$key] = $number;
    }

    /**
     * @param  array<string, mixed>  $values
     * @param  array<string, string>  $errors
     */
    private function documentSize(mixed $input, array &$values, array &$errors): void
    {
        $max = $this->documentMaxKb();
        $kb = is_int($input) ? $input : (is_string($input) && preg_match('/^\d{1,9}$/', trim($input)) === 1 ? (int) trim($input) : null);

        if ($kb === null || $kb < self::DOCUMENT_MIN_KB) {
            $errors[Settings::DOCUMENT_MAX_SIZE_KB] = 'La taille maximale d\'un document est d\'au moins 1 Mo.';

            return;
        }

        // Une valeur déjà enregistrée au-dessus de la limite de PHP reste acceptée telle quelle, pour ne pas bloquer les autres réglages.
        if ($kb > $max && $kb !== $this->current(Settings::DOCUMENT_MAX_SIZE_KB)) {
            $errors[Settings::DOCUMENT_MAX_SIZE_KB] = 'La taille maximale d\'un document ne peut pas dépasser '.intdiv($max, 1024).' Mo (limite de PHP sur ce serveur ou plafond de l\'application).';

            return;
        }

        $values[Settings::DOCUMENT_MAX_SIZE_KB] = $kb;
    }
}
