<?php

namespace App\Actions\Maintenance;

use App\Actions\Concerns\GuardsOperations;
use App\Actions\Documents\AttachDocuments;
use App\Enums\AssetStatus;
use App\Enums\MaintenanceResult;
use App\Enums\MaintenanceStatus;
use App\Enums\PermissionAbility;
use App\Enums\PermissionModule;
use App\Enums\ReportStatus;
use App\Models\AssetCondition;
use App\Models\AssetReport;
use App\Models\DocumentType;
use App\Models\MaintenanceJob;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * RG-61 : à la clôture, on choisit le résultat, le nouvel état et le nouveau statut (statuts manuels seulement).
 * Un résultat « irréparable » fait proposer une sortie du patrimoine. RG-62 : le coût ne touche ni la valeur
 * d'origine du bien ni son plan d'amortissement.
 */
class CloseMaintenanceJob
{
    use GuardsOperations;

    public function __construct(private AttachDocuments $attachDocuments) {}

    /**
     * @param  array<string, mixed>  $data
     * @param  int|null  $expectedVersion  RG-86 : version du bien lue à l'ouverture du formulaire
     * @param  iterable<UploadedFile>  $files  facture ou rapport, facultatif
     */
    public function __invoke(MaintenanceJob $job, array $data, ?int $expectedVersion = null, iterable $files = [], ?DocumentType $documentType = null): MaintenanceJob
    {
        $this->authorizeModule(PermissionModule::Maintenance, PermissionAbility::Update, 'Vous n\'avez pas le droit de clôturer une intervention.');

        $result = ($data['result'] ?? null) instanceof MaintenanceResult ? $data['result'] : MaintenanceResult::tryFrom((string) ($data['result'] ?? ''));
        $condition = filled($data['condition_after_id'] ?? null) ? AssetCondition::query()->where('is_active', true)->find($data['condition_after_id']) : null;
        $endsOn = $this->date($data['ends_on'] ?? null, 'ends_on', 'La date de fin est obligatoire.');
        $cost = $this->money($data['cost'] ?? null, 'cost');
        $statusAfter = ($data['status_after'] ?? null) instanceof AssetStatus ? $data['status_after'] : AssetStatus::tryFrom((string) ($data['status_after'] ?? ''));
        $errors = [];

        if ($result === null) {
            $errors['result'] = 'Le résultat de l\'intervention est obligatoire.';
        }

        if ($condition === null) {
            $errors['condition_after_id'] = 'Choisissez le nouvel état du bien.';
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        $files = collect($files)->values();

        return DB::transaction(function () use ($job, $data, $result, $condition, $endsOn, $cost, $statusAfter, $expectedVersion, $files, $documentType): MaintenanceJob {
            $current = MaintenanceJob::query()->lockForUpdate()->find($job->getKey());

            if ($current === null) {
                throw ValidationException::withMessages(['job' => 'Cette intervention est introuvable ou hors de votre périmètre.']);
            }

            if ($current->status !== MaintenanceStatus::Ongoing) {
                throw ValidationException::withMessages(['job' => $current->status === MaintenanceStatus::Done
                    ? 'Cette intervention est déjà clôturée.'
                    : 'Démarrez l\'intervention avant de la clôturer.']);
            }

            if ($endsOn->isBefore($current->starts_on)) {
                throw ValidationException::withMessages(['ends_on' => 'La date de fin ne peut pas précéder la date de début.']);
            }

            $asset = $this->lockAssets([$current->asset_id])->first();

            if ($asset === null) {
                throw ValidationException::withMessages(['job' => 'Le bien est introuvable ou hors de votre périmètre.']);
            }

            // RG-12 : seuls les statuts manuels se choisissent ; un bien emprunté garde son statut Emprunté.
            $changeStatus = ! $asset->isDisposed()
                && ($asset->status === AssetStatus::InMaintenance || $asset->status->isManuallySelectable());

            if ($changeStatus && ($statusAfter === null || ! $statusAfter->isManuallySelectable())) {
                throw ValidationException::withMessages(['status_after' => 'Choisissez le nouveau statut : En service, En stock ou Hors service.']);
            }

            $old = ['status' => $asset->status->getLabel(), 'condition' => $asset->condition?->label];

            if (! $asset->isDisposed()) {
                $this->assertVersion($asset, $expectedVersion);

                $asset->update([
                    'asset_condition_id' => $condition->getKey(),
                    ...($changeStatus ? ['status' => $statusAfter] : []),
                ]);
                $asset->unsetRelation('condition');
            }

            $current->update([
                'result' => $result,
                'condition_after_id' => $condition->getKey(),
                'status_after' => $changeStatus ? $statusAfter : null,
                'ends_on' => $endsOn->toDateString(),
                // QL8-6 : le coût de la fenêtre remplace celui du devis, y compris vidé ; absent, il est conservé.
                'cost' => array_key_exists('cost', $data) ? $cost : $current->cost,
                'cost_reference' => array_key_exists('cost_reference', $data) ? $this->clean($data['cost_reference']) : $current->cost_reference,
                'notes' => $this->clean($data['notes'] ?? null) ?? $current->notes,
                'status' => MaintenanceStatus::Done,
            ]);

            // RG-64 : un signalement traité par cette intervention est traité à sa clôture.
            AssetReport::query()->withoutGlobalScopes()
                ->where('handled_ref_type', $current->getMorphClass())
                ->where('handled_ref_id', $current->getKey())
                ->where('status', ReportStatus::InProgress)
                ->lockForUpdate()
                ->get()
                ->each(fn (AssetReport $report) => $report->update(['status' => ReportStatus::Handled, 'handled_at' => now()]));

            activity('maintenance')
                ->performedOn($asset)
                ->event('closed')
                ->withProperties([
                    'old' => $old,
                    'attributes' => [
                        'status' => $asset->status->getLabel(),
                        'condition' => $condition->label,
                        'reference' => $current->reference,
                        'result' => $result->getLabel(),
                        'ends_on' => $endsOn->format('d/m/Y'),
                    ],
                ])
                ->log('Clôture d\'une intervention');

            if ($files->isNotEmpty() && $documentType instanceof DocumentType && ! $asset->isDisposed()) {
                ($this->attachDocuments)($files, $documentType, [$asset]);
            }

            return $current->load(['asset', 'conditionAfter']);
        }, attempts: 3);
    }
}
