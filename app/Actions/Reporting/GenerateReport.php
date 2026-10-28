<?php

namespace App\Actions\Reporting;

use App\Models\User;
use App\Services\Reporting\ReportBuilder;
use App\Support\Reports\ReportCatalog;
use App\Support\Reports\ReportData;
use App\Support\Reports\ReportFilters;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Gate;
use InvalidArgumentException;

/**
 * E29 : produit un rapport pour l'utilisateur. Le droit est revérifié à chaque appel, le rapport et les filtres
 * viennent du navigateur ; seul le périmètre de l'utilisateur est lu (RG-81).
 */
class GenerateReport
{
    public function __construct(private ReportBuilder $builder) {}

    /**
     * @param  array<string, mixed>  $filters
     */
    public function __invoke(User $user, string $key, array $filters, bool $forExport = false): ReportData
    {
        $definition = ReportCatalog::find($key) ?? throw new InvalidArgumentException('Rapport inconnu.');

        if (! Gate::forUser($user)->allows($forExport ? 'export' : 'view', $definition)) {
            throw new AuthorizationException($forExport ? 'Vous n\'avez pas le droit d\'exporter ce rapport.' : 'Vous n\'avez pas accès à ce rapport.');
        }

        return $this->builder->build($definition, ReportFilters::forReport($definition, $filters), $user);
    }
}
