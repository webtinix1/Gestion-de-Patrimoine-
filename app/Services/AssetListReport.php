<?php

namespace App\Services;

use App\Models\Asset;
use App\Support\AssetListData;
use Illuminate\Database\Eloquent\Builder;

/**
 * Construit le contenu des exports de la liste des biens à partir de la requête de l'écran :
 * filtres, recherche, tri et périmètre de l'utilisateur sont déjà appliqués à cette requête.
 */
class AssetListReport
{
    public const array HEADINGS = [
        'N° patrimonial', 'Désignation', 'Marque / modèle', 'Catégorie', 'Site', 'Département',
        'Local', 'Responsable', 'État', 'Statut', 'Date d\'acquisition', 'Valeur (FCFA)',
    ];

    public function __construct(private Settings $settings) {}

    /**
     * @param  Builder<Asset>  $query
     * @param  list<string>  $filters  libellés lisibles des filtres actifs
     */
    public function build(Builder $query, array $filters = []): AssetListData
    {
        $assets = $query
            ->with(['category', 'site', 'department', 'room', 'holder', 'condition'])
            ->get();

        $rows = $assets->map(fn (Asset $asset): array => [
            $asset->number,
            $asset->designation,
            trim(($asset->brand ?? '').' '.($asset->model ?? '')) ?: null,
            $asset->category->name,
            $asset->site->name,
            $asset->department->name,
            $asset->room?->name,
            $asset->holder?->name,
            $asset->condition->label,
            $asset->status->getLabel(),
            $asset->acquired_on->format('d/m/Y'),
            $asset->acquisition_cost,
        ])->values()->all();

        return new AssetListData(
            headings: self::HEADINGS,
            rows: $rows,
            count: $assets->count(),
            total: (int) $assets->sum('acquisition_cost'),
            filters: $filters,
            churchName: $this->settings->string(Settings::CHURCH_NAME),
            churchAcronym: $this->settings->string(Settings::CHURCH_ACRONYM),
            editedAt: now(),
        );
    }
}
