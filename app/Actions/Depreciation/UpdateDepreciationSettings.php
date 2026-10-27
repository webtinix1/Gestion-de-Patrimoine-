<?php

namespace App\Actions\Depreciation;

use App\Services\DepreciationCalculator;
use App\Services\Settings;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * E32 : paramètres généraux des amortissements. Ils ne s'appliquent qu'aux nouveaux biens : aucun plan
 * existant n'est recalculé, le seuil a déjà décidé du caractère amortissable de chaque fiche et le
 * coefficient dégressif est écrit sur la fiche au premier calcul (RG-35). Désactiver le dégressif
 * n'impose le linéaire qu'aux nouveaux biens.
 */
class UpdateDepreciationSettings
{
    /**
     * @var list<string>
     */
    public const array KEYS = [
        Settings::CAPITALIZATION_THRESHOLD,
        Settings::DECLINING_ENABLED,
        Settings::DECLINING_COEFFICIENT_3_4,
        Settings::DECLINING_COEFFICIENT_5_6,
        Settings::DECLINING_COEFFICIENT_OVER_6,
    ];

    public function __construct(private Settings $settings) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function __invoke(array $data): void
    {
        $values = [];
        $errors = [];

        $threshold = $data[Settings::CAPITALIZATION_THRESHOLD] ?? null;

        if (! is_numeric($threshold) || (int) $threshold < 0) {
            $errors[Settings::CAPITALIZATION_THRESHOLD] = 'Le seuil doit être un montant positif ou nul.';
        } else {
            $values[Settings::CAPITALIZATION_THRESHOLD] = (int) $threshold;
        }

        $values[Settings::DECLINING_ENABLED] = (bool) ($data[Settings::DECLINING_ENABLED] ?? false);

        foreach ([Settings::DECLINING_COEFFICIENT_3_4, Settings::DECLINING_COEFFICIENT_5_6, Settings::DECLINING_COEFFICIENT_OVER_6] as $key) {
            try {
                $scaled = DepreciationCalculator::parseScaled((string) ($data[$key] ?? ''));

                if ($scaled <= 0 || $scaled > DepreciationCalculator::MAX_COEFFICIENT) {
                    throw new InvalidArgumentException;
                }

                $values[$key] = rtrim(rtrim(DepreciationCalculator::formatScaled($scaled), '0'), '.');
            } catch (InvalidArgumentException) {
                $errors[$key] = 'Coefficient invalide : un nombre entre 0 et 10 (exemple : 1,5).';
            }
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        $old = [];
        $new = [];

        foreach ($values as $key => $value) {
            $before = $this->settings->get($key);

            if ($before !== $value) {
                $old[$key] = $before;
                $new[$key] = $value;
                $this->settings->set($key, $value);
            }
        }

        if ($new !== []) {
            // RG-70 : modification d'une règle d'amortissement.
            activity('amortissements')
                ->event('depreciation_settings_updated')
                ->withProperties(['old' => $old, 'attributes' => $new])
                ->log('Modification des règles générales d\'amortissement');
        }
    }
}
