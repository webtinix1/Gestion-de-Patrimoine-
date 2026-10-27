<?php

namespace App\Actions\Assets;

use App\Actions\Depreciation\SyncDepreciationSchedule;
use App\Models\Asset;
use App\Models\AssetCategory;
use App\Services\AssetNumberGenerator;
use Illuminate\Support\Facades\DB;

/**
 * Crée une fiche de bien et lui attribue son numéro patrimonial (RG-01) dans la même transaction.
 */
class CreateAsset
{
    public function __construct(
        private AssetNumberGenerator $numbers,
        private ApplyAssetRules $rules,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function __invoke(array $data): Asset
    {
        $category = AssetCategory::query()->findOrFail($data['asset_category_id']);
        $data = ($this->rules)($data, $category);
        $data['assigned_since'] ??= $data['acquired_on'] ?? now()->toDateString();

        // RG-03 : transaction la plus externe, rejouée en cas d'interblocage sur le compteur.
        return DB::transaction(function () use ($data, $category): Asset {
            $asset = new Asset($data);
            $asset->forceFill(['number' => $this->numbers->next($category)]);
            $asset->save();
            app(SyncDepreciationSchedule::class)($asset);

            return $asset;
        }, attempts: 5);
    }
}
