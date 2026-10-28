<?php

namespace App\Support\Reports;

use App\Enums\AcquisitionStatus;
use App\Enums\AssetStatus;
use App\Enums\DisposalAccountingStatus;
use App\Enums\InventoryLineResult;
use App\Enums\LoanStatus;
use App\Enums\MaintenanceStatus;
use App\Enums\PermissionAbility;
use App\Enums\PermissionModule;
use App\Models\User;

/**
 * E29 : catalogue des rapports. Chaque rapport est réservé aux utilisateurs qui peuvent consulter les rapports
 * et exporter le module concerné (QL11-1).
 */
final class ReportCatalog
{
    public const string GROUP_ASSETS = 'Patrimoine';

    public const string GROUP_OPERATIONS = 'Opérations';

    public const string GROUP_DEPRECIATION = 'Immobilisations';

    private const array ASSET_FILTERS = ['site', 'department', 'service', 'category', 'status', 'holder', 'period'];

    private const array ASSET_FILTERS_NO_STATUS = ['site', 'department', 'service', 'category', 'holder', 'period'];

    private const array DEPRECIATION_FILTERS = ['site', 'department', 'service', 'category', 'holder', 'year'];

    /** @var array<string, ReportDefinition>|null */
    private static ?array $definitions = null;

    /**
     * @return array<string, ReportDefinition>
     */
    public static function all(): array
    {
        return self::$definitions ??= collect([
            new ReportDefinition('general', 'Inventaire général', self::GROUP_ASSETS, PermissionModule::Assets, self::ASSET_FILTERS, AssetStatus::class),
            new ReportDefinition('by_department', 'Patrimoine par département', self::GROUP_ASSETS, PermissionModule::Assets, self::ASSET_FILTERS, AssetStatus::class),
            new ReportDefinition('by_site', 'Patrimoine par site', self::GROUP_ASSETS, PermissionModule::Assets, self::ASSET_FILTERS, AssetStatus::class),
            new ReportDefinition('by_category', 'Patrimoine par catégorie', self::GROUP_ASSETS, PermissionModule::Assets, self::ASSET_FILTERS, AssetStatus::class),
            new ReportDefinition('by_room', 'Patrimoine par local', self::GROUP_ASSETS, PermissionModule::Assets, self::ASSET_FILTERS, AssetStatus::class),
            new ReportDefinition('acquisitions', 'Acquisitions', self::GROUP_OPERATIONS, PermissionModule::Acquisitions, ['site', 'department', 'category', 'status', 'period'], AcquisitionStatus::class),
            new ReportDefinition('movements', 'Mouvements', self::GROUP_OPERATIONS, PermissionModule::Movements, self::ASSET_FILTERS_NO_STATUS),
            new ReportDefinition('loans', 'Prêts et emprunts', self::GROUP_OPERATIONS, PermissionModule::Loans, [...self::ASSET_FILTERS_NO_STATUS, 'status'], LoanStatus::class),
            new ReportDefinition('maintenance', 'Maintenance', self::GROUP_OPERATIONS, PermissionModule::Maintenance, [...self::ASSET_FILTERS_NO_STATUS, 'status'], MaintenanceStatus::class),
            new ReportDefinition('disposals', 'Sorties et réformes', self::GROUP_OPERATIONS, PermissionModule::Disposals, [...self::ASSET_FILTERS_NO_STATUS, 'status', 'year'], DisposalAccountingStatus::class),
            new ReportDefinition('inventory_gaps', 'Écarts d\'inventaire', self::GROUP_OPERATIONS, PermissionModule::Inventories, ['site', 'department', 'category', 'status', 'period'], InventoryLineResult::class),
            new ReportDefinition('depreciation_register', 'Tableau des immobilisations', self::GROUP_DEPRECIATION, PermissionModule::Depreciation, self::DEPRECIATION_FILTERS, null, true),
            new ReportDefinition('depreciation_charges', 'Dotations par exercice', self::GROUP_DEPRECIATION, PermissionModule::Depreciation, self::DEPRECIATION_FILTERS, null, true),
            new ReportDefinition('depreciation_accumulated', 'Amortissements cumulés', self::GROUP_DEPRECIATION, PermissionModule::Depreciation, self::DEPRECIATION_FILTERS, null, true),
            new ReportDefinition('net_book_value', 'Valeurs nettes comptables', self::GROUP_DEPRECIATION, PermissionModule::Depreciation, self::DEPRECIATION_FILTERS, null, true),
            new ReportDefinition('fully_depreciated', 'Immobilisations totalement amorties', self::GROUP_DEPRECIATION, PermissionModule::Depreciation, self::DEPRECIATION_FILTERS, null, true),
        ])->keyBy(fn (ReportDefinition $definition): string => $definition->key)->all();
    }

    public static function find(string $key): ?ReportDefinition
    {
        return self::all()[$key] ?? null;
    }

    /**
     * Consulter l'aperçu : droit « consulter » des rapports et « exporter » du module concerné.
     */
    public static function canView(User $user, ReportDefinition $report): bool
    {
        return $user->is_active
            && $user->can(PermissionModule::Reporting->permission(PermissionAbility::View))
            && $user->can($report->module->permission(PermissionAbility::Export));
    }

    /**
     * Exporter en PDF ou Excel : droit « exporter » des rapports et du module concerné.
     */
    public static function canExport(User $user, ReportDefinition $report): bool
    {
        return self::canView($user, $report)
            && $user->can(PermissionModule::Reporting->permission(PermissionAbility::Export));
    }

    /**
     * @return array<string, list<ReportDefinition>> rapports accessibles à l'utilisateur, par groupe
     */
    public static function availableTo(User $user): array
    {
        $groups = [];

        foreach (self::all() as $report) {
            if (self::canView($user, $report)) {
                $groups[$report->group][] = $report;
            }
        }

        return $groups;
    }
}
