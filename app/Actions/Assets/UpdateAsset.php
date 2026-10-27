<?php

namespace App\Actions\Assets;

use App\Actions\Depreciation\SyncDepreciationSchedule;
use App\Exceptions\ConcurrentModificationException;
use App\Models\Asset;
use App\Models\AssetCategory;
use App\Models\User;
use App\Services\MovementRecorder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Modifie une fiche. Le numéro ne bouge jamais (RG-04) ; une fiche sortie est en lecture seule (RG-52).
 */
class UpdateAsset
{
    public function __construct(private ApplyAssetRules $rules, private MovementRecorder $movements) {}

    /**
     * @param  array<string, mixed>  $data
     * @param  int|null  $expectedVersion  RG-86 : version de la fiche lue à l'ouverture du formulaire.
     */
    public function __invoke(Asset $asset, array $data, ?int $expectedVersion = null): Asset
    {
        return DB::transaction(function () use ($asset, $data, $expectedVersion): Asset {
            // RG-86 : contrôle de version et enregistrement sous le même verrou, sans écart possible.
            $current = Asset::query()->withoutGlobalScopes()->lockForUpdate()->findOrFail($asset->getKey());

            if ($expectedVersion !== null && $current->lock_version !== $expectedVersion) {
                throw new ConcurrentModificationException(
                    $current->updated_by ? User::query()->find($current->updated_by) : null,
                    $current->updated_at,
                );
            }

            if ($current->isDisposed()) {
                throw ValidationException::withMessages([
                    'data.designation' => 'Ce bien est sorti du patrimoine : sa fiche ne peut plus être modifiée.',
                ]);
            }

            unset($data['number'], $data['lock_version']);

            // RG-12 : un statut posé par une opération (prêt, maintenance) ne se change pas à la main.
            if (! $current->status->isManuallySelectable()) {
                unset($data['status']);
            }

            $category = AssetCategory::query()->findOrFail($data['asset_category_id'] ?? $current->asset_category_id);
            $before = $this->movements->assignmentOf($current);
            $current->fill(($this->rules)(
                [...$current->only(['acquisition_cost', 'depreciation_forced_off', 'status']), ...$data],
                $category,
                $current->status,
                $current->id,
            ));

            // RG-21 : un changement d'affectation fait depuis la fiche laisse aussi un mouvement.
            $after = $this->movements->assignmentOf($current);
            $assignmentChanged = $this->movements->changedElements($before, $after) !== [];

            if ($assignmentChanged) {
                $current->assigned_since = today();
            }

            $current->save();

            // Le recalcul du plan s'appuie sur les modifications enregistrées (getChanges).
            app(SyncDepreciationSchedule::class)->afterAssetUpdate($current);

            if ($assignmentChanged) {
                $this->movements->record($current, $before, $after, today(), 'Modification de la fiche');
            }

            $asset->setRawAttributes($current->getAttributes(), sync: true);

            return $asset;
        }, attempts: 3);
    }
}
