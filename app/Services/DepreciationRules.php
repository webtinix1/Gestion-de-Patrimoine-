<?php

namespace App\Services;

use App\Enums\DepreciationMethod;
use App\Models\Asset;
use App\Models\AssetCategory;

/**
 * Paramètres généraux des amortissements (E32), avec les valeurs par défaut de docs/06.
 */
class DepreciationRules
{
    public function __construct(private Settings $settings) {}

    // RG-35 / Q4 : le dégressif reste désactivé tant que le comptable ne l'a pas retenu.
    public function decliningEnabled(): bool
    {
        return $this->settings->bool(Settings::DECLINING_ENABLED);
    }

    /**
     * RG-35 : coefficient selon la durée (1,5 pour 3 à 4 ans, 2 pour 5 à 6 ans, 2,5 au-delà).
     * Aucun coefficient sous 3 ans : le dégressif ne s'applique pas.
     */
    public function coefficientFor(int $durationYears): ?string
    {
        $key = match (true) {
            $durationYears < 3 => null,
            $durationYears <= 4 => Settings::DECLINING_COEFFICIENT_3_4,
            $durationYears <= 6 => Settings::DECLINING_COEFFICIENT_5_6,
            default => Settings::DECLINING_COEFFICIENT_OVER_6,
        };

        if ($key === null) {
            return null;
        }

        return DepreciationCalculator::formatScaled(DepreciationCalculator::parseScaled($this->settings->string($key)));
    }

    /**
     * Méthode d'un bien amortissable. Un bien existant garde la méthode portée par sa fiche, même si
     * le dégressif a été désactivé depuis : seule l'action « Modifier les paramètres » la change.
     * Un nouveau bien prend la méthode demandée ou celle de la catégorie, linéaire si le dégressif
     * est désactivé (RG-35).
     */
    public function methodFor(AssetCategory $category, mixed $requested = null, ?int $assetId = null): DepreciationMethod
    {
        $stored = $assetId === null ? null : Asset::query()->withoutGlobalScopes()->whereKey($assetId)->first()?->depreciation_method;

        if ($stored !== null) {
            return $stored;
        }

        if (! $this->decliningEnabled()) {
            return DepreciationMethod::Linear;
        }

        $method = $requested instanceof DepreciationMethod ? $requested : DepreciationMethod::tryFrom(is_string($requested) ? $requested : '');

        return $method ?? $category->default_method;
    }

    public function prorataLabel(): string
    {
        return 'Temporis en jours, année de 360 jours (mois de 30 jours)';
    }
}
