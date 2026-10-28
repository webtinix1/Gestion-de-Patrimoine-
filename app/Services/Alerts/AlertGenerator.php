<?php

namespace App\Services\Alerts;

use App\Enums\AlertType;
use App\Enums\AssetStatus;
use App\Enums\InventoryLineResult;
use App\Enums\InventoryStatus;
use App\Enums\LoanStatus;
use App\Enums\MaintenanceStatus;
use App\Models\Alert;
use App\Models\Asset;
use App\Models\DepreciationSchedule;
use App\Models\Inventory;
use App\Models\InventoryLine;
use App\Models\Loan;
use App\Models\MaintenanceJob;
use App\Services\Settings;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * RG-90, RG-91 : détecte les causes d'alerte sur tout le patrimoine (sans périmètre : il s'applique à la
 * lecture, voir Alert::scopeVisibleTo) et synchronise la table des alertes.
 * Idempotent : une cause = une empreinte = une ligne, quel que soit le nombre de passages.
 */
class AlertGenerator
{
    /** Les alertes résolues sont conservées ce nombre de jours, puis purgées. */
    private const int KEEP_RESOLVED_DAYS = 30;

    public function __construct(private Settings $settings) {}

    /**
     * @return array{raised: int, kept: int, resolved: int, purged: int}
     */
    public function refresh(?CarbonImmutable $today = null): array
    {
        $today ??= CarbonImmutable::today();
        $now = now();
        $detected = $this->detect($today);

        return DB::transaction(function () use ($detected, $now): array {
            $raised = 0;
            $kept = 0;
            $existing = Alert::query()->whereIn('fingerprint', array_keys($detected))->get()->keyBy('fingerprint');

            foreach ($detected as $fingerprint => $cause) {
                $alert = $existing->get($fingerprint);

                if ($alert === null) {
                    // Insertion tolérante : un lancement manuel pendant le passage planifié ne doit pas échouer.
                    $inserted = DB::table('alerts')->insertOrIgnore([...$cause, 'fingerprint' => $fingerprint, 'raised_at' => $now, 'created_at' => $now, 'updated_at' => $now]);
                    $raised += $inserted;
                    $kept += 1 - $inserted;

                    continue;
                }

                if ($alert->resolved_at !== null) {
                    // La cause est revenue : nouvelle alerte, à lire de nouveau (RG-91).
                    $alert->readers()->detach();
                    $alert->forceFill([...$cause, 'raised_at' => $now, 'resolved_at' => null])->save();
                    $raised++;

                    continue;
                }

                $alert->fill($cause);

                if ($alert->isDirty()) {
                    $alert->save();
                }

                $kept++;
            }

            // RG-91 : une alerte dont la cause est levée disparaît.
            $resolved = Alert::query()->active()
                ->when($detected !== [], fn ($query) => $query->whereNotIn('fingerprint', array_keys($detected)))
                ->update(['resolved_at' => $now]);

            $purged = Alert::query()->whereNotNull('resolved_at')->where('resolved_at', '<', $now->copy()->subDays(self::KEEP_RESOLVED_DAYS))->delete();

            return ['raised' => $raised, 'kept' => $kept, 'resolved' => $resolved, 'purged' => $purged];
        });
    }

    /**
     * @return array<string, array{type: string, subject_type: string, subject_id: int, event_on: string|null}>
     */
    public function detect(CarbonImmutable $today): array
    {
        $causes = [];
        $add = function (AlertType $type, Model $subject, ?CarbonImmutable $eventOn) use (&$causes): void {
            $causes[$type->value.':'.$subject->getKey()] = [
                'type' => $type->value,
                'subject_type' => $subject::class,
                'subject_id' => (int) $subject->getKey(),
                'event_on' => $eventOn?->toDateString(),
            ];
        };

        // Prêts et emprunts en cours : retard de retour, ou échéance proche (3 jours par défaut).
        $dueLimit = $today->addDays(max(0, $this->settings->int(Settings::ALERT_LOAN_DUE_DAYS)));

        foreach (Loan::query()->withoutGlobalScopes()->where('status', LoanStatus::Ongoing->value)->where('due_on', '<=', $dueLimit->toDateString())->get() as $loan) {
            $due = CarbonImmutable::parse($loan->due_on->toDateString());
            $add($due->lt($today) ? AlertType::LoanOverdue : AlertType::LoanDueSoon, $loan, $due);
        }

        // Intervention ouverte depuis plus de 15 jours par défaut.
        $maintenanceLimit = $today->subDays(max(0, $this->settings->int(Settings::ALERT_MAINTENANCE_DAYS)));

        foreach (MaintenanceJob::query()->withoutGlobalScopes()
            ->whereIn('status', [MaintenanceStatus::Planned->value, MaintenanceStatus::Ongoing->value])
            ->where('starts_on', '<=', $maintenanceLimit->toDateString())
            ->get() as $job) {
            $add(AlertType::MaintenanceOpen, $job, CarbonImmutable::parse($job->starts_on->toDateString()));
        }

        // Biens déclarés hors service et toujours au patrimoine.
        foreach (Asset::query()->withoutGlobalScopes()->where('status', AssetStatus::OutOfService->value)->whereNull('disposed_at')->get() as $asset) {
            $add(AlertType::OutOfService, $asset, null);
        }

        // Inventaires planifiés qui commencent dans 7 jours par défaut (ou dont la date est passée).
        $inventoryLimit = $today->addDays(max(0, $this->settings->int(Settings::ALERT_INVENTORY_DAYS)));

        foreach (Inventory::query()->withoutGlobalScopes()->where('status', InventoryStatus::Planned->value)->where('starts_on', '<=', $inventoryLimit->toDateString())->get() as $inventory) {
            $add(AlertType::InventoryUpcoming, $inventory, CarbonImmutable::parse($inventory->starts_on->toDateString()));
        }

        // Écarts d'un inventaire clôturé qui n'ont pas été régularisés.
        $withGaps = InventoryLine::query()->withoutGlobalScopes()
            ->whereIn('result', InventoryLineResult::discrepancyValues())
            ->whereNull('resolution')
            ->whereIn('inventory_id', Inventory::query()->withoutGlobalScopes()->where('status', InventoryStatus::Closed->value)->select('id'))
            ->distinct()
            ->pluck('inventory_id');

        foreach (Inventory::query()->withoutGlobalScopes()->whereKey($withGaps)->get() as $inventory) {
            $add(AlertType::InventoryGaps, $inventory, $inventory->closed_at === null ? null : CarbonImmutable::parse($inventory->closed_at->toDateString()));
        }

        // Fin d'amortissement dans moins de 3 mois par défaut.
        $endLimit = $today->addMonths(max(0, $this->settings->int(Settings::ALERT_DEPRECIATION_MONTHS)));
        $schedules = DepreciationSchedule::query()
            ->where('is_current', true)
            ->whereBetween('ends_on', [$today->toDateString(), $endLimit->toDateString()])
            ->get()
            ->keyBy('asset_id');

        foreach (Asset::query()->withoutGlobalScopes()->whereKey($schedules->keys())->whereNull('disposed_at')->get() as $asset) {
            $ends = $schedules->get($asset->getKey())?->ends_on;
            $add(AlertType::DepreciationEnding, $asset, $ends === null ? null : CarbonImmutable::parse($ends->toDateString()));
        }

        return $causes;
    }
}
