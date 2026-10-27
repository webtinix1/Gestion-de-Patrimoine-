<?php

namespace App\Actions\Acquisitions;

use App\Actions\Assets\CreateAsset;
use App\Enums\AcquisitionStatus;
use App\Enums\AssetStatus;
use App\Models\Acquisition;
use App\Models\AcquisitionLine;
use App\Models\Asset;
use App\Models\AssetCondition;
use DomainException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * RG-15 : la validation crée une fiche et un numéro par unité des lignes à suivi individuel
 * (quantité 3 = 3 fiches) ; une ligne non suivie (consommable) ne crée aucune fiche.
 * Tout se passe dans une seule transaction : aucune fiche ne subsiste si l'une échoue.
 * Les paramètres d'amortissement viennent de la catégorie de la ligne (RG-31), via CreateAsset.
 */
class ValidateAcquisition
{
    public function __construct(private CreateAsset $createAsset) {}

    /**
     * @return Collection<int, Asset> fiches créées, dans l'ordre des numéros
     */
    public function __invoke(Acquisition $acquisition, bool $attachDocumentsToAssets = false): Collection
    {
        return DB::transaction(function () use ($acquisition, $attachDocumentsToAssets): Collection {
            $locked = Acquisition::query()->whereKey($acquisition->getKey())->lockForUpdate()->firstOrFail();

            if (! $locked->isDraft()) {
                throw new DomainException('Cette acquisition est déjà validée.');
            }

            $lines = $locked->lines()->with('category')->get();

            if ($lines->isEmpty()) {
                throw new DomainException('Une acquisition sans ligne ne peut pas être validée.');
            }

            if ($lines->contains(fn ($line): bool => $line->tracked_individually && ! $line->category->is_active)) {
                throw new DomainException('Une ligne utilise une catégorie désactivée : choisissez une catégorie active avant de valider.');
            }

            $condition = $this->condition();
            $assets = $lines->where('tracked_individually', true)->flatMap(
                fn (AcquisitionLine $line): array => array_map(fn (int $unit): Asset => ($this->createAsset)([
                    'designation' => $line->designation,
                    'asset_category_id' => $line->asset_category_id,
                    'acquired_on' => $locked->acquired_on->toDateString(),
                    'supplier_id' => $locked->supplier_id,
                    'invoice_reference' => $locked->invoice_reference,
                    'acquisition_cost' => $line->unit_price,
                    'funding_source' => $locked->funding_source,
                    'site_id' => $locked->site_id,
                    'department_id' => $locked->department_id,
                    'asset_condition_id' => $condition->getKey(),
                    'status' => AssetStatus::InService,
                    'acquisition_line_id' => $line->getKey(),
                ]), range(1, $line->quantity)),
            )->values();

            $locked->disableLogging();
            $locked->forceFill([
                'status' => AcquisitionStatus::Validated,
                'validated_at' => now(),
                'validated_by' => auth()->id(),
            ])->save();
            $locked->enableLogging();

            if ($attachDocumentsToAssets && $assets->isNotEmpty()) {
                foreach ($locked->documents as $document) {
                    $document->assets()->syncWithoutDetaching($assets->map->getKey()->all());
                }
            }

            // RG-70 : la validation est tracée avec les numéros créés.
            activity('acquisitions')
                ->performedOn($locked)
                ->event('validated')
                ->withProperties([
                    'reference' => $locked->reference,
                    'numbers' => $assets->pluck('number')->all(),
                    'assets_count' => $assets->count(),
                    'documents_attached_to_assets' => $attachDocumentsToAssets,
                ])
                ->log('Validation d\'une acquisition');

            $acquisition->setRawAttributes($locked->getAttributes(), true);

            return $assets;
        }, attempts: 5);
    }

    private function condition(): AssetCondition
    {
        $conditions = AssetCondition::query()->active()->orderBy('sort')->get();

        $condition = $conditions->firstWhere('label', 'Neuf') ?? $conditions->first();

        return $condition ?? throw new DomainException('Aucun état n\'est disponible : ajoutez-en un dans Catégories et référentiels.');
    }
}
