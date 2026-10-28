<?php

namespace App\Services\Reporting;

use App\Enums\AssetStatus;
use App\Enums\InventoryStatus;
use App\Enums\LoanDirection;
use App\Enums\LoanStatus;
use App\Enums\MaintenanceStatus;
use App\Enums\MovementType;
use App\Enums\PermissionAbility;
use App\Enums\PermissionModule;
use App\Models\Alert;
use App\Models\Asset;
use App\Models\AssetReport;
use App\Models\Department;
use App\Models\Inventory;
use App\Models\Loan;
use App\Models\MaintenanceJob;
use App\Models\Movement;
use App\Models\Site;
use App\Models\User;
use App\Services\Inventories\InventoryReport;
use Illuminate\Database\Eloquent\Builder;

/**
 * Données du tableau de bord (E02) et de l'espace du responsable (E42). Les chiffres viennent de PatrimonyFigures,
 * comme ceux des rapports ; chaque bloc n'est lu que si l'utilisateur a le droit de consulter le module concerné,
 * et tout est limité à son périmètre (RG-81).
 */
class DashboardData
{
    private const int TOP_DEPARTMENTS = 7;

    public function __construct(
        private PatrimonyFigures $figures,
        private ReportBuilder $reports,
        private InventoryReport $inventoryReport,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function overview(User $user): array
    {
        $year = $this->reports->currentYear();
        $can = fn (PermissionModule $module): bool => $this->can($user, $module);
        $assets = $can(PermissionModule::Assets);
        $totals = $assets ? $this->figures->activeTotals() : null;
        $statuses = $assets ? $this->figures->statusCounts() : null;
        $depreciation = $can(PermissionModule::Depreciation) ? $this->figures->depreciation($year)['totals'] : null;

        return [
            'year' => $year,
            'values' => [
                'acquisition' => $totals['acquisition'] ?? null,
                'net' => $depreciation['net'] ?? null,
                'charges' => $depreciation['amount'] ?? null,
            ],
            'counters' => [
                'total' => $statuses === null ? null : array_sum($statuses),
                'active' => $totals['count'] ?? null,
                'new_this_month' => $assets ? Asset::query()->whereNull('disposed_at')->where('acquired_on', '>=', now()->startOfMonth()->toDateString())->count() : null,
                'statuses' => $statuses,
                'open_jobs' => $can(PermissionModule::Maintenance) ? MaintenanceJob::query()->whereIn('status', [MaintenanceStatus::Planned->value, MaintenanceStatus::Ongoing->value])->count() : null,
                'overdue_loans' => $can(PermissionModule::Loans) ? $this->overdueLoans() : null,
            ],
            'departments' => $assets ? $this->departments() : null,
            'sites' => $assets ? $this->figures->bySite($year) : null,
            'recent_assets' => $assets ? Asset::query()->with('category')->orderByDesc('acquired_on')->orderByDesc('id')->limit(4)->get() : null,
            'recent_transfers' => $can(PermissionModule::Movements) ? $this->recentTransfers() : null,
            'to_handle' => $assets ? $this->assetsToHandle() : null,
            'last_inventory' => $can(PermissionModule::Inventories) ? $this->lastInventory() : null,
            'unread_alerts' => Alert::query()->active()->visibleTo($user)->unreadBy($user)->count(),
        ];
    }

    /**
     * E42 : vue d'ensemble du périmètre du responsable de département.
     *
     * @return array<string, mixed>
     */
    public function departmentSpace(User $user): array
    {
        $statuses = $this->figures->statusCounts();
        $departments = Department::query()->with('services')->orderBy('name')->get();
        $sites = Site::query()->whereIn('id', Asset::query()->select('site_id'))->orderBy('name')->get();
        $can = fn (PermissionModule $module): bool => $this->can($user, $module);

        return [
            'departments' => $departments,
            'sites' => $sites,
            'counters' => [
                'assigned' => $this->figures->activeTotals()['count'],
                'in_service' => $statuses[AssetStatus::InService->value],
                'in_maintenance' => $statuses[AssetStatus::InMaintenance->value],
                'on_loan' => $statuses[AssetStatus::OnLoan->value],
                'out_of_service' => $statuses[AssetStatus::OutOfService->value],
            ],
            // RG-82 : inventaires où il est désigné, ou compris dans son périmètre.
            'next_inventory' => $can(PermissionModule::Inventories)
                ? Inventory::query()->whereIn('status', [InventoryStatus::Planned->value, InventoryStatus::InProgress->value])->orderBy('starts_on')->first()
                : null,
            'my_reports' => $can(PermissionModule::AssetReports)
                ? AssetReport::query()->with('asset')->where('created_by', $user->getKey())->orderByDesc('created_at')->orderByDesc('id')->limit(5)->get()
                : null,
            'assets' => $this->activeAssetsQuery()->with(['room', 'condition'])->orderBy('number')->limit(5)->get(),
            'assets_total' => $this->activeAssetsQuery()->count(),
            'unread_alerts' => Alert::query()->active()->visibleTo($user)->unreadBy($user)->count(),
        ];
    }

    /**
     * @return Builder<Asset>
     */
    public function activeAssetsQuery(): Builder
    {
        return $this->figures->activeAssets();
    }

    public function can(User $user, PermissionModule $module): bool
    {
        return $user->is_active && $user->can($module->permission(PermissionAbility::View));
    }

    /**
     * @return array{items: list<array{id: int, name: string, count: int, percent: int}>, others: array{count: int, number: int}|null}
     */
    private function departments(): array
    {
        $rows = collect($this->figures->byDepartment())->sortByDesc('count')->values();
        $max = max(1, (int) $rows->max('count'));
        $top = $rows->take(self::TOP_DEPARTMENTS);
        $rest = $rows->slice(self::TOP_DEPARTMENTS);

        return [
            'items' => $top->map(fn (array $row): array => [
                'id' => $row['id'],
                'name' => $row['name'],
                'count' => $row['count'],
                'percent' => (int) round($row['count'] * 100 / $max),
            ])->values()->all(),
            'others' => $rest->isEmpty() ? null : ['count' => (int) $rest->sum('count'), 'number' => $rest->count()],
        ];
    }

    private function overdueLoans(): int
    {
        return Loan::query()
            ->where('direction', LoanDirection::Out->value)
            ->where('status', LoanStatus::Ongoing->value)
            ->where('due_on', '<', today()->toDateString())
            ->count();
    }

    /**
     * @return list<Movement>
     */
    private function recentTransfers(): array
    {
        return Movement::query()
            ->with(['asset', 'fromDepartment', 'toDepartment', 'fromSite', 'toSite', 'fromRoom', 'toRoom', 'fromHolder', 'toHolder'])
            ->where('type', '!=', MovementType::Initial->value)
            ->orderByDesc('moved_on')
            ->orderByDesc('id')
            ->limit(3)
            ->get()
            ->all();
    }

    /**
     * Biens à traiter : hors service d'abord, puis en maintenance.
     *
     * @return list<Asset>
     */
    private function assetsToHandle(): array
    {
        return $this->activeAssetsQuery()
            ->whereIn('assets.status', [AssetStatus::OutOfService->value, AssetStatus::InMaintenance->value])
            ->orderByRaw('case when assets.status = ? then 0 else 1 end', [AssetStatus::OutOfService->value])
            ->orderBy('assets.number')
            ->limit(6)
            ->get()
            ->all();
    }

    /**
     * @return array{inventory: Inventory, counters: array<string, int>}|null
     */
    private function lastInventory(): ?array
    {
        $inventory = Inventory::query()->where('status', InventoryStatus::Closed->value)->orderByDesc('closed_at')->orderByDesc('id')->first();

        return $inventory === null ? null : ['inventory' => $inventory, 'counters' => $this->inventoryReport->counters($inventory)];
    }
}
