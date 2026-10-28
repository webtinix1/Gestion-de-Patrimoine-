<?php

namespace App\Actions\Reports;

use App\Actions\Concerns\GuardsOperations;
use App\Enums\PermissionAbility;
use App\Enums\PermissionModule;
use App\Enums\ReportStatus;
use App\Enums\ReportType;
use App\Enums\ReportUrgency;
use App\Models\Asset;
use App\Models\AssetCondition;
use App\Models\AssetReport;
use App\Models\Department;
use App\Models\Room;
use App\Services\OperationReferenceGenerator;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * RG-63 : un responsable signale, sur un bien de son périmètre, un changement d'état, un déplacement ou un besoin
 * de maintenance. Le signalement ne modifie jamais le bien.
 */
class CreateAssetReport
{
    use GuardsOperations;

    public const string PHOTO_DIRECTORY = 'signalements/photos';

    public function __construct(private OperationReferenceGenerator $references) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function __invoke(array $data, ?UploadedFile $photo = null): AssetReport
    {
        $this->authorizeModule(PermissionModule::AssetReports, PermissionAbility::Create, 'Vous n\'avez pas le droit de faire un signalement.');

        // RG-81 : le scope du bien s'applique, un bien hors périmètre n'est pas trouvé.
        $asset = filled($data['asset_id'] ?? null) ? Asset::query()->find($data['asset_id']) : null;

        if ($asset === null) {
            throw ValidationException::withMessages(['asset_id' => 'Ce bien est introuvable ou hors de votre périmètre.']);
        }

        if ($asset->isDisposed()) {
            throw ValidationException::withMessages(['asset_id' => 'Ce bien est sorti du patrimoine.']);
        }

        $type = ($data['type'] ?? null) instanceof ReportType ? $data['type'] : ReportType::tryFrom((string) ($data['type'] ?? ''));
        $urgency = ($data['urgency'] ?? null) instanceof ReportUrgency ? $data['urgency'] : ReportUrgency::tryFrom((string) ($data['urgency'] ?? ''));
        $description = $this->clean($data['description'] ?? null);
        $errors = [];

        if ($type === null) {
            $errors['type'] = 'Choisissez la nature du signalement.';
        }

        if ($urgency === null) {
            $errors['urgency'] = 'Choisissez l\'urgence.';
        }

        if ($description === null || mb_strlen($description) < 3) {
            $errors['description'] = 'Décrivez le problème en quelques mots.';
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        [$conditionId, $roomId, $departmentId] = $this->target($type, $asset, $data);

        if ($photo instanceof UploadedFile) {
            Validator::make(['photo' => $photo], ['photo' => ['image', 'mimes:jpg,jpeg,png,webp', 'max:5120']], [], ['photo' => 'photo'])->validate();
        }

        $path = $photo instanceof UploadedFile ? (string) $photo->store(self::PHOTO_DIRECTORY, 'local') : null;

        try {
            return DB::transaction(function () use ($asset, $type, $urgency, $description, $conditionId, $roomId, $departmentId, $path): AssetReport {
                $report = AssetReport::query()->create([
                    'reference' => $this->references->next('SIG', today()->year),
                    'asset_id' => $asset->getKey(),
                    'type' => $type,
                    'reported_condition_id' => $conditionId,
                    'reported_room_id' => $roomId,
                    'reported_department_id' => $departmentId,
                    'urgency' => $urgency,
                    'description' => mb_substr((string) $description, 0, 2000),
                    'photo_path' => $path,
                    'status' => ReportStatus::New,
                ]);

                activity('signalements')
                    ->performedOn($asset)
                    ->event('reported')
                    ->withProperties(['attributes' => [
                        'reference' => $report->reference,
                        'type' => $type->getLabel(),
                        'urgency' => $urgency->getLabel(),
                    ]])
                    ->log('Signalement d\'un bien');

                return $report;
            }, attempts: 3);
        } catch (Throwable $exception) {
            if ($path !== null) {
                Storage::disk('local')->delete($path);
            }

            throw $exception;
        }
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{0: int|null, 1: int|null, 2: int|null}
     */
    private function target(ReportType $type, Asset $asset, array $data): array
    {
        if ($type === ReportType::Condition) {
            $condition = filled($data['reported_condition_id'] ?? null) ? AssetCondition::query()->where('is_active', true)->find($data['reported_condition_id']) : null;

            if ($condition === null) {
                throw ValidationException::withMessages(['reported_condition_id' => 'Choisissez l\'état constaté.']);
            }

            if ($condition->getKey() === $asset->asset_condition_id) {
                throw ValidationException::withMessages(['reported_condition_id' => 'Cet état est déjà celui de la fiche du bien.']);
            }

            return [$condition->getKey(), null, null];
        }

        if ($type === ReportType::Move) {
            $room = filled($data['reported_room_id'] ?? null) ? Room::query()->where('is_active', true)->find($data['reported_room_id']) : null;
            // Le département demandé peut être hors du périmètre du responsable : on ne retient que son nom.
            $department = filled($data['reported_department_id'] ?? null) ? Department::query()->withoutGlobalScopes()->where('is_active', true)->find($data['reported_department_id']) : null;

            if ($room === null && $department === null) {
                throw ValidationException::withMessages(['reported_room_id' => 'Indiquez où se trouve le bien ou qui l\'utilise.']);
            }

            if (($room === null || $room->getKey() === $asset->room_id) && ($department === null || $department->getKey() === $asset->department_id)) {
                throw ValidationException::withMessages(['reported_room_id' => 'Cet emplacement est déjà celui de la fiche du bien.']);
            }

            return [null, $room?->getKey(), $department?->getKey()];
        }

        return [null, null, null];
    }
}
