<?php

namespace App\Services;

use App\Enums\Depreciability;
use App\Models\AssetCategory;

/**
 * RG-30 : un bien est amortissable si sa catégorie l'est et si son prix atteint le seuil
 * d'immobilisation ; le gestionnaire peut forcer « non amortissable ».
 */
class DepreciabilityResolver
{
    public function __construct(private Settings $settings) {}

    public function isDepreciable(AssetCategory $category, ?int $cost, bool $forcedOff = false): bool
    {
        if ($forcedOff || $category->depreciable === Depreciability::No) {
            return false;
        }

        if ($cost === null || $cost <= 0) {
            return false;
        }

        return $category->depreciable === Depreciability::Yes
            || $cost >= $this->settings->int(Settings::CAPITALIZATION_THRESHOLD);
    }

    /**
     * RG-10 : le prix d'acquisition est obligatoire si le bien est amortissable.
     */
    public function requiresCost(AssetCategory $category, bool $forcedOff = false): bool
    {
        return ! $forcedOff && $category->depreciable === Depreciability::Yes;
    }
}
