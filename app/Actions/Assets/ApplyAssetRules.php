<?php

namespace App\Actions\Assets;

use App\Enums\AssetStatus;
use App\Enums\DepreciationMethod;
use App\Models\AssetCategory;
use App\Services\DepreciabilityResolver;
use App\Services\SerialNumberGuard;
use Illuminate\Validation\ValidationException;

/**
 * Règles communes à la création et à la modification d'une fiche (RG-10, RG-12, RG-13, RG-30, RG-31).
 */
class ApplyAssetRules
{
    public function __construct(
        private DepreciabilityResolver $depreciability,
        private SerialNumberGuard $serialNumbers,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function __invoke(array $data, AssetCategory $category, ?AssetStatus $currentStatus = null, ?int $assetId = null): array
    {
        $existing = $this->serialNumbers->conflictFor($data['serial_number'] ?? null, $assetId);

        if ($existing !== null) {
            throw ValidationException::withMessages(['data.serial_number' => SerialNumberGuard::message($existing)]);
        }

        $status = $data['status'] ?? null;
        $status = $status instanceof AssetStatus ? $status : AssetStatus::tryFrom((string) $status);

        if ($status === null || (! $status->isManuallySelectable() && $status !== $currentStatus)) {
            throw ValidationException::withMessages([
                'data.status' => 'Ce statut est posé par une opération (prêt, maintenance, sortie) et ne se choisit pas à la main.',
            ]);
        }

        $cost = isset($data['acquisition_cost']) && $data['acquisition_cost'] !== '' ? (int) $data['acquisition_cost'] : null;
        $forcedOff = (bool) ($data['depreciation_forced_off'] ?? false);

        if ($cost === null && $this->depreciability->requiresCost($category, $forcedOff)) {
            throw ValidationException::withMessages([
                'data.acquisition_cost' => 'Le prix d\'acquisition est obligatoire pour un bien amortissable.',
            ]);
        }

        $data['acquisition_cost'] = $cost;
        $data['is_depreciable'] = $this->depreciability->isDepreciable($category, $cost, $forcedOff);

        if ($data['is_depreciable']) {
            // RG-31 : paramètres par défaut issus de la catégorie, modifiables sur la fiche.
            // RG-35 : le dégressif reste désactivé tant que le comptable ne l'a pas retenu.
            $data['depreciation_method'] = DepreciationMethod::Linear;
            $data['duration_years'] = ($data['duration_years'] ?? null) ?: $category->default_duration_years;
            $data['residual_value'] = (int) ($data['residual_value'] ?? 0);
            $data['asset_account'] = ($data['asset_account'] ?? null) ?: $category->asset_account;
            $data['depreciation_account'] = ($data['depreciation_account'] ?? null) ?: $category->depreciation_account;
        } else {
            $data['depreciation_method'] = null;
            $data['duration_years'] = null;
            $data['residual_value'] = 0;
        }

        return $data;
    }
}
