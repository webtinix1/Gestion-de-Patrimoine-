<?php

namespace Database\Seeders;

use App\Enums\Depreciability;
use App\Enums\DepreciationMethod;
use App\Models\AssetCategory;
use App\Models\AssetCondition;
use App\Models\DocumentType;
use Illuminate\Database\Seeder;

/**
 * Données de référence indispensables, chargées aussi en production.
 * N'écrase jamais une valeur déjà présente : le client a pu la modifier.
 */
class ReferenceSeeder extends Seeder
{
    /**
     * RG-11 : états par défaut, dans l'ordre d'affichage.
     *
     * @var list<array{0: string, 1: string}>
     */
    public const array CONDITIONS = [
        ['Neuf', 'success'],
        ['Très bon état', 'success'],
        ['Bon état', 'primary'],
        ['État moyen', 'warning'],
        ['À réparer', 'warning'],
        ['Hors service', 'danger'],
    ];

    /**
     * Catégories de la maquette (E37). Durées à confirmer et comptes SYSCOHADA
     * à compléter par le comptable (docs/06, Q3).
     *
     * @var list<array{0: string, 1: string, 2: Depreciability, 3: int|null, 4: string|null, 5: string|null}>
     */
    public const array CATEGORIES = [
        ['Matériel informatique', 'INF', Depreciability::Yes, 3, '2441', '28441'],
        ['Mobilier', 'MOB', Depreciability::Yes, 10, null, null],
        ['Matériel audiovisuel', 'AUD', Depreciability::Yes, 5, null, null],
        ['Matériel de sonorisation', 'SON', Depreciability::Yes, 5, null, null],
        ['Matériel électrique', 'ELE', Depreciability::Yes, 8, null, null],
        ['Matériel de bureau', 'BUR', Depreciability::ThresholdBased, 5, null, null],
        ['Matériel de transport', 'TRA', Depreciability::Yes, 5, null, null],
        ['Matériel de culte', 'CUL', Depreciability::ThresholdBased, 5, null, null],
        ['Matériel de cuisine', 'CUI', Depreciability::ThresholdBased, 5, null, null],
        ['Autres équipements', 'AUT', Depreciability::No, null, null, null],
    ];

    /**
     * @var list<string>
     */
    public const array DOCUMENT_TYPES = [
        'Facture d\'achat',
        'Bon de livraison',
        'PV de réception',
        'Document de transfert',
        'Rapport d\'inventaire',
        'Document de réforme',
        'Justificatif de maintenance',
        'Document de prêt',
        'Plan d\'amortissement',
        'Photo',
    ];

    public function run(): void
    {
        foreach (self::CONDITIONS as $sort => [$label, $color]) {
            AssetCondition::query()->firstOrCreate(
                ['label' => $label],
                ['color' => $color, 'sort' => $sort + 1],
            );
        }

        foreach (self::CATEGORIES as [$name, $prefix, $depreciable, $duration, $assetAccount, $depreciationAccount]) {
            AssetCategory::query()->firstOrCreate(
                ['prefix' => $prefix],
                [
                    'name' => $name,
                    'depreciable' => $depreciable,
                    'default_method' => DepreciationMethod::Linear,
                    'default_duration_years' => $duration,
                    'asset_account' => $assetAccount,
                    'depreciation_account' => $depreciationAccount,
                ],
            );
        }

        foreach (self::DOCUMENT_TYPES as $label) {
            DocumentType::query()->firstOrCreate(['label' => $label]);
        }

        $this->call(RolesSeeder::class);
    }
}
