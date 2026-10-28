<?php

namespace App\Policies;

use App\Models\User;
use App\Support\Reports\ReportCatalog;
use App\Support\Reports\ReportDefinition;

/**
 * E29 : un rapport se consulte avec le droit « consulter » des rapports, et se consulte comme s'exporte
 * avec le droit « exporter » du module concerné (QL11-1). Aucun rapport ne se modifie ni ne se supprime.
 */
class ReportDefinitionPolicy
{
    public function view(User $user, ReportDefinition $report): bool
    {
        return ReportCatalog::canView($user, $report);
    }

    public function export(User $user, ReportDefinition $report): bool
    {
        return ReportCatalog::canExport($user, $report);
    }
}
