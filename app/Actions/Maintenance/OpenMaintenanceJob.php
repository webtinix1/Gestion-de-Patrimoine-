<?php

namespace App\Actions\Maintenance;

use App\Actions\Concerns\GuardsOperations;
use App\Actions\Documents\AttachDocuments;
use App\Actions\Reports\ProcessAssetReport;
use App\Enums\AssetStatus;
use App\Enums\MaintenanceNature;
use App\Enums\MaintenanceStatus;
use App\Enums\PermissionAbility;
use App\Enums\PermissionModule;
use App\Enums\ProviderType;
use App\Models\Asset;
use App\Models\DocumentType;
use App\Models\MaintenanceJob;
use App\Services\OperationReferenceGenerator;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * RG-60 : ouvre une intervention (planifiée si elle commence plus tard). RG-61 : à l'ouverture, on peut passer le bien
 * En maintenance. RG-62 : le coût reste sur l'intervention.
 */
class OpenMaintenanceJob
{
    use GuardsOperations;

    public function __construct(
        private OperationReferenceGenerator $references,
        private AttachDocuments $attachDocuments,
        private ProcessAssetReport $reports,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     * @param  int|null  $expectedVersion  RG-86 : version du bien lue à l'ouverture du formulaire
     * @param  iterable<UploadedFile>  $files  devis ou justificatif, facultatif
     */
    public function __invoke(array $data, ?int $expectedVersion = null, iterable $files = [], ?DocumentType $documentType = null): MaintenanceJob
    {
        $this->authorizeModule(PermissionModule::Maintenance, PermissionAbility::Create, 'Vous n\'avez pas le droit d\'ouvrir une intervention.');

        $fields = $this->fields($data);
        $startsOn = $this->date($data['starts_on'] ?? null, 'starts_on', 'La date de début est obligatoire.', allowFuture: true);
        $assetId = (int) ($data['asset_id'] ?? 0);
        $reportId = filled($data['report_id'] ?? null) ? (int) $data['report_id'] : null;
        $files = collect($files)->values();

        if ($assetId === 0) {
            throw ValidationException::withMessages(['asset_id' => 'Choisissez le bien concerné.']);
        }

        return DB::transaction(function () use ($data, $fields, $startsOn, $assetId, $reportId, $expectedVersion, $files, $documentType): MaintenanceJob {
            $asset = $this->lockAssets([$assetId], 'asset_id')->first();

            if (! $asset instanceof Asset) {
                throw ValidationException::withMessages(['asset_id' => 'Ce bien est introuvable ou hors de votre périmètre.']);
            }

            if ($asset->isDisposed()) {
                throw ValidationException::withMessages(['asset_id' => 'Un bien sorti du patrimoine ne peut pas faire l\'objet d\'une intervention.']);
            }

            $open = MaintenanceJob::query()->where('asset_id', $asset->getKey())->whereIn('status', [MaintenanceStatus::Planned, MaintenanceStatus::Ongoing])->first();

            if ($open !== null) {
                throw ValidationException::withMessages(['asset_id' => 'Ce bien a déjà une intervention ouverte ('.$open->reference.').']);
            }

            $this->assertVersion($asset, $expectedVersion);

            $status = $startsOn->isAfter(today()) ? MaintenanceStatus::Planned : MaintenanceStatus::Ongoing;
            $put = (bool) ($data['put_in_maintenance'] ?? false) && $status === MaintenanceStatus::Ongoing;

            if ($put && $asset->status === AssetStatus::OnLoan) {
                throw ValidationException::withMessages(['put_in_maintenance' => 'Ce bien est emprunté : enregistrez d\'abord son retour avant de le passer En maintenance.']);
            }

            $job = MaintenanceJob::query()->create([
                ...$fields,
                'reference' => $this->references->next('MNT', $startsOn->year),
                'asset_id' => $asset->getKey(),
                'starts_on' => $startsOn->toDateString(),
                'status' => $status,
            ]);

            $old = ['status' => $asset->status->getLabel()];

            if ($put && $asset->status !== AssetStatus::InMaintenance) {
                $asset->update(['status' => AssetStatus::InMaintenance]);
            }

            // RG-70 : maintenance tracée.
            activity('maintenance')
                ->performedOn($asset)
                ->event('opened')
                ->withProperties([
                    'old' => $old,
                    'attributes' => [
                        'status' => $asset->status->getLabel(),
                        'reference' => $job->reference,
                        'nature' => $job->nature->getLabel(),
                        'starts_on' => $startsOn->format('d/m/Y'),
                    ],
                ])
                ->log('Ouverture d\'une intervention');

            if ($files->isNotEmpty() && $documentType instanceof DocumentType) {
                ($this->attachDocuments)($files, $documentType, [$asset]);
            }

            if ($reportId !== null) {
                $this->reports->linkOperation($reportId, $asset, $job);
            }

            return $job;
        }, attempts: 3);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function fields(array $data): array
    {
        $nature = ($data['nature'] ?? null) instanceof MaintenanceNature ? $data['nature'] : MaintenanceNature::tryFrom((string) ($data['nature'] ?? ''));
        $provider = ($data['provider_type'] ?? null) instanceof ProviderType ? $data['provider_type'] : ProviderType::tryFrom((string) ($data['provider_type'] ?? ''));
        $reason = $this->clean($data['reason'] ?? null);
        $errors = [];

        if ($nature === null) {
            $errors['nature'] = 'La nature de l\'intervention est obligatoire.';
        }

        if ($provider === null) {
            $errors['provider_type'] = 'Indiquez si l\'intervenant est interne ou un prestataire.';
        }

        if ($reason === null) {
            $errors['reason'] = 'Le motif est obligatoire.';
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return [
            'nature' => $nature,
            'reason' => mb_substr((string) $reason, 0, 255),
            'provider_type' => $provider,
            'provider_name' => $this->clean($data['provider_name'] ?? null),
            'cost' => $this->money($data['cost'] ?? null, 'cost'),
            'cost_reference' => $this->clean($data['cost_reference'] ?? null),
            'notes' => $this->clean($data['notes'] ?? null),
        ];
    }
}
