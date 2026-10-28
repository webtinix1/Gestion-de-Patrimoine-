<?php

namespace App\Actions\Loans;

use App\Actions\Concerns\GuardsOperations;
use App\Actions\Documents\AttachDocuments;
use App\Enums\AssetStatus;
use App\Enums\LoanStatus;
use App\Enums\PermissionAbility;
use App\Enums\PermissionModule;
use App\Models\Asset;
use App\Models\AssetCondition;
use App\Models\DocumentType;
use App\Models\Loan;
use App\Models\LoanItem;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * RG-56 : enregistre le retour d'un prêt (ou la restitution d'un emprunt) : date effective, état au retour de chaque
 * bien, statut précédent restauré. RG-57 : un état moins bon qu'au départ met à jour l'état du bien.
 */
class RecordLoanReturn
{
    use GuardsOperations;

    public function __construct(private AttachDocuments $attachDocuments) {}

    /**
     * @param  array{returned_on: mixed, items: array<int|string, array{condition_back_id?: mixed, return_notes?: mixed}>}  $data  lignes indexées par identifiant de ligne
     * @param  array<int, int>  $expectedVersions  RG-86 : version lue à l'ouverture, par identifiant de bien
     * @param  iterable<UploadedFile>  $files  photo ou PV de retour, facultatif
     */
    public function __invoke(
        Loan $loan,
        array $data,
        array $expectedVersions = [],
        iterable $files = [],
        ?DocumentType $documentType = null,
    ): Loan {
        $this->authorizeModule(PermissionModule::Loans, PermissionAbility::Update, 'Vous n\'avez pas le droit d\'enregistrer un retour.');

        $returnedOn = $this->date($data['returned_on'] ?? null, 'returned_on', 'La date de retour effective est obligatoire.');
        $input = collect($data['items'])->mapWithKeys(fn (mixed $item, mixed $id): array => [(int) $id => (array) $item]);
        $files = collect($files)->values();

        return DB::transaction(function () use ($loan, $returnedOn, $input, $expectedVersions, $files, $documentType): Loan {
            // Le prêt d'abord, puis ses biens : même ordre que la clôture d'une intervention.
            $locked = Loan::query()->lockForUpdate()->find($loan->getKey());

            if ($locked === null) {
                throw ValidationException::withMessages(['loan' => 'Ce prêt est introuvable ou hors de votre périmètre.']);
            }

            if (! $locked->isOngoing()) {
                throw ValidationException::withMessages(['loan' => 'Le retour de ce prêt est déjà enregistré.']);
            }

            if ($returnedOn->isBefore($locked->out_on)) {
                throw ValidationException::withMessages(['returned_on' => 'La date de retour ne peut pas précéder la date de sortie.']);
            }

            $items = $locked->items()->orderBy('id')->get();

            // RG-81 : le retour porte sur tout le prêt, il ne peut pas laisser de côté un bien hors périmètre.
            if ($items->count() !== $locked->items()->withoutGlobalScopes()->count()) {
                throw ValidationException::withMessages(['loan' => 'Ce prêt comporte des biens hors de votre périmètre.']);
            }

            if ($input->keys()->sort()->values()->all() !== $items->pluck('id')->sort()->values()->all()) {
                throw ValidationException::withMessages(['items' => 'Indiquez l\'état au retour de chaque bien du prêt.']);
            }

            $conditions = AssetCondition::query()
                ->whereKey($input->pluck('condition_back_id')->filter()->unique()->all())
                ->get()
                ->keyBy('id');

            foreach ($input as $row) {
                if (! $conditions->has((int) ($row['condition_back_id'] ?? 0))) {
                    throw ValidationException::withMessages(['items' => 'Indiquez l\'état au retour de chaque bien du prêt.']);
                }
            }

            $assets = $this->lockAssets($items->pluck('asset_id')->filter());

            foreach ($items as $item) {
                $row = $input[$item->getKey()];
                $back = $conditions[(int) $row['condition_back_id']];
                $asset = $item->asset_id === null ? null : $assets[$item->asset_id];

                $item->update([
                    'condition_back_id' => $back->getKey(),
                    'return_notes' => $this->clean($row['return_notes'] ?? null),
                ]);

                if ($asset !== null) {
                    $this->restore($asset, $item, $back, $locked, $returnedOn, $expectedVersions);
                }
            }

            $locked->update(['status' => LoanStatus::Returned, 'returned_on' => $returnedOn->toDateString()]);

            if ($files->isNotEmpty() && $documentType instanceof DocumentType && $assets->isNotEmpty()) {
                ($this->attachDocuments)($files, $documentType, $assets->reject(fn (Asset $asset): bool => $asset->isDisposed()));
            }

            if ($assets->isEmpty()) {
                activity('prets')
                    ->performedOn($locked)
                    ->event('returned')
                    ->withProperties(['attributes' => ['reference' => $locked->reference, 'returned_on' => $returnedOn->format('d/m/Y')]])
                    ->log('Restitution d\'un bien emprunté');
            }

            return $locked->load(['items.asset', 'items.conditionOut', 'items.conditionBack']);
        }, attempts: 3);
    }

    /**
     * @param  array<int, int>  $expectedVersions
     */
    private function restore(Asset $asset, LoanItem $item, AssetCondition $back, Loan $loan, Carbon $returnedOn, array $expectedVersions): void
    {
        // Un bien sorti du patrimoine pendant le prêt n'est plus touché : seule la ligne garde le constat.
        if ($asset->isDisposed()) {
            return;
        }

        $this->assertVersion($asset, $expectedVersions[$asset->getKey()] ?? null);

        $old = ['status' => $asset->status->getLabel(), 'condition' => $asset->condition?->label];
        $changes = [];

        if ($asset->status === AssetStatus::OnLoan) {
            $previous = $item->previous_status;
            $changes['status'] = $previous !== null && $previous->isManuallySelectable() ? $previous : AssetStatus::InService;
        }

        // RG-57 : l'ordre des états est le champ « sort ».
        $degraded = $back->sort > (int) $item->conditionOut?->sort;

        if ($degraded) {
            $changes['asset_condition_id'] = $back->getKey();
        }

        if ($changes !== []) {
            $asset->update($changes);
        }

        $asset->unsetRelation('condition');

        // RG-70 : retour tracé, avec le statut et l'état avant et après.
        activity('prets')
            ->performedOn($asset)
            ->event('returned')
            ->withProperties([
                'old' => $old,
                'attributes' => [
                    'status' => $asset->status->getLabel(),
                    'condition' => $asset->condition?->label,
                    'reference' => $loan->reference,
                    'returned_on' => $returnedOn->format('d/m/Y'),
                ],
            ])
            ->log('Retour d\'un bien prêté');
    }
}
