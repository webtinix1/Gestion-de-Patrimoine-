<?php

namespace App\Actions\Maintenance;

use App\Actions\Concerns\GuardsOperations;
use App\Enums\MaintenanceStatus;
use App\Enums\PermissionAbility;
use App\Enums\PermissionModule;
use App\Exceptions\ConcurrentModificationException;
use App\Models\Asset;
use App\Models\MaintenanceJob;
use App\Models\User;
use Filament\Support\Contracts\HasLabel;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Modifie une intervention ouverte (planifiée ou en cours). RG-86 : contrôle de version sous verrou.
 */
class UpdateMaintenanceJob
{
    use GuardsOperations;

    public function __construct(private OpenMaintenanceJob $open) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function __invoke(MaintenanceJob $job, array $data, ?int $expectedVersion = null): MaintenanceJob
    {
        $this->authorizeModule(PermissionModule::Maintenance, PermissionAbility::Update, 'Vous n\'avez pas le droit de modifier une intervention.');

        $fields = $this->open->fields($data);
        $startsOn = $this->date($data['starts_on'] ?? null, 'starts_on', 'La date de début est obligatoire.', allowFuture: true);

        return DB::transaction(function () use ($job, $fields, $startsOn, $expectedVersion): MaintenanceJob {
            $current = MaintenanceJob::query()->lockForUpdate()->find($job->getKey());

            if ($current === null) {
                throw ValidationException::withMessages(['job' => 'Cette intervention est introuvable ou hors de votre périmètre.']);
            }

            if ($expectedVersion !== null && $current->lock_version !== $expectedVersion) {
                throw new ConcurrentModificationException(
                    $current->updated_by ? User::query()->find($current->updated_by) : null,
                    $current->updated_at,
                );
            }

            if (! $current->isOpen()) {
                throw ValidationException::withMessages(['job' => 'Une intervention terminée n\'est plus modifiable.']);
            }

            if ($current->status === MaintenanceStatus::Planned && ! $startsOn->isAfter(today())) {
                throw ValidationException::withMessages(['starts_on' => 'Une intervention planifiée garde une date de début à venir : pour la commencer maintenant, utilisez « Démarrer l\'intervention ».']);
            }

            if ($current->status === MaintenanceStatus::Ongoing && $startsOn->isAfter(today())) {
                throw ValidationException::withMessages(['starts_on' => 'Une intervention en cours ne peut pas commencer dans le futur.']);
            }

            $startsBefore = $current->starts_on;
            $before = $current->only(['nature', 'reason', 'provider_type', 'provider_name', 'cost', 'cost_reference', 'notes']);
            $current->update([...$fields, 'starts_on' => $startsOn->toDateString()]);

            $old = [];
            $new = [];

            foreach (['nature', 'reason', 'provider_type', 'provider_name', 'cost', 'cost_reference', 'notes'] as $key) {
                $was = $before[$key];
                $now = $current->getAttribute($key);

                if ($was !== $now) {
                    $old[$key] = $was instanceof HasLabel ? $was->getLabel() : $was;
                    $new[$key] = $now instanceof HasLabel ? $now->getLabel() : $now;
                }
            }

            if ($current->wasChanged('starts_on')) {
                $old['starts_on'] = $startsBefore->format('d/m/Y');
                $new['starts_on'] = $startsOn->format('d/m/Y');
            }

            if ($new !== []) {
                activity('maintenance')
                    ->performedOn(Asset::query()->withoutGlobalScopes()->findOrFail($current->asset_id))
                    ->event('updated')
                    ->withProperties(['old' => $old, 'attributes' => $new + ['reference' => $current->reference]])
                    ->log('Modification d\'une intervention');
            }

            return $current;
        }, attempts: 3);
    }
}
