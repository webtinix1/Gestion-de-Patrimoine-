<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

/**
 * RG-80 : modules de la matrice des droits (E39).
 */
enum PermissionModule: string implements HasLabel
{
    case Assets = 'assets';
    case Labels = 'labels';
    case Acquisitions = 'acquisitions';
    case Movements = 'movements';
    case Inventories = 'inventories';
    case Loans = 'loans';
    case Maintenance = 'maintenance';
    case AssetReports = 'asset_reports';
    case Disposals = 'disposals';
    case Depreciation = 'depreciation';
    case Documents = 'documents';
    case Reporting = 'reporting';
    case Structure = 'structure';
    case Users = 'users';
    case Settings = 'settings';
    case ActivityLog = 'activity_log';

    public function getLabel(): string
    {
        return match ($this) {
            self::Assets => 'Biens',
            self::Labels => 'Étiquettes',
            self::Acquisitions => 'Acquisitions',
            self::Movements => 'Mouvements',
            self::Inventories => 'Inventaires',
            self::Loans => 'Prêts',
            self::Maintenance => 'Maintenance',
            self::AssetReports => 'Signalements',
            self::Disposals => 'Sorties et réformes',
            self::Depreciation => 'Immobilisations et amortissements',
            self::Documents => 'Documents',
            self::Reporting => 'Rapports',
            self::Structure => 'Structure (sites, départements, locaux, catégories)',
            self::Users => 'Utilisateurs et rôles',
            self::Settings => 'Paramètres',
            self::ActivityLog => 'Journal des opérations',
        };
    }

    public function permission(PermissionAbility $ability): string
    {
        return $this->value.'.'.$ability->value;
    }
}
