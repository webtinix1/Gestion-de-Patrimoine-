<?php

namespace App\Actions\Maintenance;

use App\Actions\Concerns\GuardsOperations;
use App\Enums\AssetStatus;
use App\Enums\MaintenanceStatus;
use App\Enums\PermissionAbility;
use App\Enums\PermissionModule;
use App\Models\MaintenanceJob;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Démarre une intervention planifiée ; RG-61 : le bien peut alors passer En maintenance.
 */
class StartMaintenanceJob
{
    use GuardsOperations;

    public function __invoke(MaintenanceJob $job, bool $putInMaintenance = false, ?int $expectedVersion = null): MaintenanceJob
    {
        $this->authorizeModule(PermissionModule::Maintenance, PermissionAbility::Update, 'Vous n\'avez pas le droit de démarrer une intervention.');

        return DB::transaction(function () use ($job, $putInMaintenance, $expectedVersion): MaintenanceJob {
            // L'intervention d'abord, puis son bien : même ordre que la clôture.
            $current = MaintenanceJob::query()->lockForUpdate()->find($job->getKey());

            if ($current === null) {
                throw ValidationException::withMessages(['job' => 'Cette intervention est introuvable ou hors de votre périmètre.']);
            }

            if ($current->status !== MaintenanceStatus::Planned) {
                throw ValidationException::withMessages(['job' => 'Seule une intervention planifiée peut être démarrée.']);
            }

            $asset = $this->lockAssets([$current->asset_id])->first();

            if ($asset === null || $asset->isDisposed()) {
                throw ValidationException::withMessages(['job' => 'Ce bien est sorti du patrimoine.']);
            }

            $this->assertVersion($asset, $expectedVersion);

            if ($putInMaintenance && $asset->status === AssetStatus::OnLoan) {
                throw ValidationException::withMessages(['put_in_maintenance' => 'Ce bien est emprunté : enregistrez d\'abord son retour avant de le passer En maintenance.']);
            }

            $old = ['status' => $asset->status->getLabel()];

            if ($current->starts_on->isAfter(today())) {
                $current->starts_on = today();
            }

            $current->status = MaintenanceStatus::Ongoing;
            $current->save();

            if ($putInMaintenance && $asset->status !== AssetStatus::InMaintenance) {
                $asset->update(['status' => AssetStatus::InMaintenance]);
            }

            activity('maintenance')
                ->performedOn($asset)
                ->event('started')
                ->withProperties([
                    'old' => $old,
                    'attributes' => ['status' => $asset->status->getLabel(), 'reference' => $current->reference, 'starts_on' => $current->starts_on->format('d/m/Y')],
                ])
                ->log('Démarrage d\'une intervention');

            return $current;
        }, attempts: 3);
    }
}
