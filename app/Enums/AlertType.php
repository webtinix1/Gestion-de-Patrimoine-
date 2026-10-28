<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

/**
 * RG-90 : types d'alertes internes. Chaque type dépend du droit « consulter » de son module : on ne voit
 * pas une alerte sur un module dont on n'a pas l'accès (RG-80).
 */
enum AlertType: string implements HasColor, HasLabel
{
    case LoanOverdue = 'loan_overdue';
    case LoanDueSoon = 'loan_due_soon';
    case MaintenanceOpen = 'maintenance_open';
    case OutOfService = 'out_of_service';
    case InventoryUpcoming = 'inventory_upcoming';
    case InventoryGaps = 'inventory_gaps';
    case DepreciationEnding = 'depreciation_ending';

    public function getLabel(): string
    {
        return match ($this) {
            self::LoanOverdue => 'Retard de retour',
            self::LoanDueSoon => 'Échéance proche',
            self::MaintenanceOpen => 'Intervention en cours',
            self::OutOfService => 'Déclaré hors service',
            self::InventoryUpcoming => 'Inventaire à réaliser',
            self::InventoryGaps => 'Écarts d\'inventaire',
            self::DepreciationEnding => 'Fin d\'amortissement',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::LoanOverdue, self::OutOfService => 'danger',
            self::LoanDueSoon, self::InventoryGaps => 'warning',
            self::MaintenanceOpen => 'info',
            self::InventoryUpcoming => 'primary',
            self::DepreciationEnding => 'gray',
        };
    }

    /** Onglet de l'écran E03. */
    public function group(): string
    {
        return match ($this) {
            self::LoanOverdue, self::LoanDueSoon => 'loans',
            self::MaintenanceOpen => 'maintenance',
            self::OutOfService => 'out_of_service',
            self::InventoryUpcoming, self::InventoryGaps => 'inventories',
            self::DepreciationEnding => 'depreciation',
        };
    }

    /**
     * @return array<string, string>
     */
    public static function groups(): array
    {
        return [
            'loans' => 'Prêts',
            'maintenance' => 'Maintenance',
            'out_of_service' => 'Hors service',
            'inventories' => 'Inventaires',
            'depreciation' => 'Amortissements',
        ];
    }

    public function module(): PermissionModule
    {
        return match ($this) {
            self::LoanOverdue, self::LoanDueSoon => PermissionModule::Loans,
            self::MaintenanceOpen => PermissionModule::Maintenance,
            self::OutOfService => PermissionModule::Assets,
            self::InventoryUpcoming, self::InventoryGaps => PermissionModule::Inventories,
            self::DepreciationEnding => PermissionModule::Depreciation,
        };
    }

    public function actionLabel(): string
    {
        return match ($this) {
            self::LoanOverdue => 'Enregistrer le retour',
            self::LoanDueSoon => 'Voir le prêt',
            self::MaintenanceOpen => 'Voir l\'intervention',
            self::OutOfService => 'Voir la fiche',
            self::InventoryUpcoming => 'Ouvrir',
            self::InventoryGaps => 'Voir les écarts',
            self::DepreciationEnding => 'Voir le plan',
        };
    }
}
