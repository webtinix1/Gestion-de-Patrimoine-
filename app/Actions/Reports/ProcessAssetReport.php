<?php

namespace App\Actions\Reports;

use App\Actions\Assets\UpdateAsset;
use App\Actions\Concerns\GuardsOperations;
use App\Actions\Movements\TransferAssets;
use App\Enums\PermissionAbility;
use App\Enums\PermissionModule;
use App\Enums\ReportStatus;
use App\Enums\ReportType;
use App\Models\Asset;
use App\Models\AssetCondition;
use App\Models\AssetReport;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * RG-64 : le gestionnaire traite un signalement en créant l'opération correspondante (intervention, transfert, mise à
 * jour d'état, sortie) ou le rejette avec un motif. Droit « valider » du module Signalements.
 *
 * Verrous : le bien d'abord, puis le signalement (comme l'ouverture d'une intervention et sa clôture).
 */
class ProcessAssetReport
{
    use GuardsOperations;

    public function __construct(private TransferAssets $transferAssets, private UpdateAsset $updateAssetAction) {}

    public function takeCharge(AssetReport $report): AssetReport
    {
        return $this->within($report, function (AssetReport $locked): AssetReport {
            $locked->update(['status' => ReportStatus::InProgress, 'handled_by' => auth()->id()]);
            $this->log($locked, 'Prise en charge d\'un signalement', 'taken');

            return $locked;
        }, onlyNew: true);
    }

    public function reject(AssetReport $report, ?string $reason): AssetReport
    {
        $reason = $this->clean($reason);

        if ($reason === null || mb_strlen($reason) < 3) {
            throw ValidationException::withMessages(['rejection_reason' => 'Le motif du rejet est obligatoire.']);
        }

        return $this->within($report, function (AssetReport $locked) use ($reason): AssetReport {
            $locked->update([
                'status' => ReportStatus::Rejected,
                'rejection_reason' => mb_substr($reason, 0, 2000),
                'handled_by' => auth()->id(),
                'handled_at' => now(),
            ]);
            $this->log($locked, 'Rejet d\'un signalement', 'rejected', ['rejection_reason' => $reason]);

            return $locked;
        });
    }

    // Traitement hors application (sortie du patrimoine, par exemple) : le gestionnaire clôt lui-même un signalement pris en charge.
    public function markHandled(AssetReport $report): AssetReport
    {
        return $this->within($report, function (AssetReport $locked): AssetReport {
            if ($locked->status !== ReportStatus::InProgress) {
                throw ValidationException::withMessages(['report' => 'Prenez d\'abord ce signalement en charge.']);
            }

            if ($locked->handled_ref_id !== null) {
                throw ValidationException::withMessages(['report' => 'Ce signalement sera traité à la clôture de l\'intervention liée.']);
            }

            $locked->update(['status' => ReportStatus::Handled, 'handled_at' => now()]);
            $this->log($locked, 'Signalement traité', 'handled');

            return $locked;
        });
    }

    /**
     * @param  array<string, mixed>  $target  affectation et date du transfert (voir TransferAssets)
     */
    public function transfer(AssetReport $report, array $target, ?int $expectedVersion = null): AssetReport
    {
        return $this->within($report, function (AssetReport $locked, Asset $asset) use ($target, $expectedVersion): AssetReport {
            if ($locked->type !== ReportType::Move) {
                throw ValidationException::withMessages(['report' => 'Ce signalement ne demande pas de déplacement.']);
            }

            $movement = ($this->transferAssets)([$asset], $target, [$asset->getKey() => $expectedVersion ?? $asset->lock_version])->first();

            $this->close($locked, $movement);

            return $locked;
        });
    }

    public function updateCondition(AssetReport $report, int $conditionId, ?int $expectedVersion = null): AssetReport
    {
        return $this->within($report, function (AssetReport $locked, Asset $asset) use ($conditionId, $expectedVersion): AssetReport {
            if ($locked->type !== ReportType::Condition) {
                throw ValidationException::withMessages(['report' => 'Ce signalement ne porte pas sur un changement d\'état.']);
            }

            $user = auth()->user();

            if ($user instanceof User && ! $user->can('update', $asset)) {
                throw new AuthorizationException('Vous n\'avez pas le droit de modifier la fiche de ce bien.');
            }

            $condition = AssetCondition::query()->where('is_active', true)->find($conditionId);

            if ($condition === null) {
                throw ValidationException::withMessages(['condition' => 'Choisissez un état actif.']);
            }

            if ($condition->getKey() === $asset->asset_condition_id) {
                throw ValidationException::withMessages(['condition' => 'Cet état est déjà celui de la fiche du bien.']);
            }

            $old = $asset->condition?->label;
            $asset->unsetRelation('condition');

            // RG-86 : la mise à jour passe par UpdateAsset, avec le contrôle de version sous verrou.
            $this->applyCondition($asset, $condition, $expectedVersion ?? $asset->lock_version);

            activity('signalements')
                ->performedOn($asset)
                ->event('condition_updated')
                ->withProperties([
                    'old' => ['condition' => $old],
                    'attributes' => ['condition' => $condition->label, 'reference' => $locked->reference],
                ])
                ->log('Mise à jour d\'état par signalement');

            $this->close($locked, $asset);

            return $locked;
        });
    }

    /**
     * Rattache l'intervention créée au signalement, qui passe Pris en charge (traité à la clôture de l'intervention).
     */
    public function linkOperation(int $reportId, Asset $asset, Model $operation): AssetReport
    {
        $report = AssetReport::query()->find($reportId);

        if ($report === null || $report->asset_id !== $asset->getKey()) {
            throw ValidationException::withMessages(['report_id' => 'Ce signalement est introuvable ou ne concerne pas ce bien.']);
        }

        return $this->within($report, function (AssetReport $locked) use ($operation): AssetReport {
            if ($locked->type === ReportType::Move) {
                throw ValidationException::withMessages(['report_id' => 'Ce signalement demande un déplacement, pas une intervention.']);
            }

            if ($locked->handled_ref_id !== null) {
                throw ValidationException::withMessages(['report_id' => 'Ce signalement est déjà lié à une opération.']);
            }

            $locked->handled_ref_type = $operation->getMorphClass();
            $locked->handled_ref_id = (int) $operation->getKey();
            $locked->status = ReportStatus::InProgress;
            $locked->handled_by = auth()->id();
            $locked->save();
            $this->log($locked, 'Intervention créée pour un signalement', 'linked', ['operation' => (string) $operation->getAttribute('reference')]);

            return $locked;
        });
    }

    private function applyCondition(Asset $asset, AssetCondition $condition, int $expectedVersion): void
    {
        // Les champs de la fiche sont repris tels quels : UpdateAsset recalcule les règles de la fiche entière.
        ($this->updateAssetAction)($asset, [
            ...$asset->only((new Asset)->getFillable()),
            'asset_condition_id' => $condition->getKey(),
        ], $expectedVersion);
    }

    /**
     * @param  callable(AssetReport, Asset): AssetReport  $callback
     */
    private function within(AssetReport $report, callable $callback, bool $onlyNew = false): AssetReport
    {
        $this->authorizeModule(PermissionModule::AssetReports, PermissionAbility::Validate, 'Vous n\'avez pas le droit de traiter un signalement.');

        // RG-81 : le signalement et son bien sont cherchés avec les scopes de périmètre.
        $visible = AssetReport::query()->find($report->getKey());

        if ($visible === null) {
            throw ValidationException::withMessages(['report' => 'Ce signalement est introuvable ou hors de votre périmètre.']);
        }

        return DB::transaction(function () use ($visible, $callback, $onlyNew): AssetReport {
            $asset = $this->lockAssets([$visible->asset_id])->first();
            $locked = AssetReport::query()->lockForUpdate()->find($visible->getKey());

            if (! $asset instanceof Asset || $locked === null) {
                throw ValidationException::withMessages(['report' => 'Ce signalement est introuvable ou hors de votre périmètre.']);
            }

            if (! $locked->status->isOpen() || ($onlyNew && $locked->status !== ReportStatus::New)) {
                throw ValidationException::withMessages(['report' => 'Ce signalement a déjà été '.mb_strtolower($locked->status->getLabel()).'.']);
            }

            return $callback($locked, $asset);
        }, attempts: 3);
    }

    private function close(AssetReport $report, Model $reference): void
    {
        $report->handled_ref_type = $reference->getMorphClass();
        $report->handled_ref_id = (int) $reference->getKey();
        $report->status = ReportStatus::Handled;
        $report->handled_by = auth()->id();
        $report->handled_at = now();
        $report->save();

        $this->log($report, 'Signalement traité', 'handled');
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    private function log(AssetReport $report, string $description, string $event, array $extra = []): void
    {
        activity('signalements')
            ->performedOn(Asset::query()->withoutGlobalScopes()->find($report->asset_id))
            ->event($event)
            ->withProperties(['attributes' => ['reference' => $report->reference, 'status' => $report->status->getLabel(), ...$extra]])
            ->log($description);
    }
}
