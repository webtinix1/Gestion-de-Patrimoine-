<?php

namespace App\Actions\Loans;

use App\Actions\Concerns\GuardsOperations;
use App\Actions\Documents\AttachDocuments;
use App\Enums\AssetStatus;
use App\Enums\BorrowerType;
use App\Enums\LoanDirection;
use App\Enums\LoanStatus;
use App\Enums\PermissionAbility;
use App\Enums\PermissionModule;
use App\Models\Asset;
use App\Models\AssetCondition;
use App\Models\Department;
use App\Models\DocumentType;
use App\Models\Loan;
use App\Models\LoanItem;
use App\Models\User;
use App\Services\OperationReferenceGenerator;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * RG-55 : prête un ou plusieurs biens disponibles. RG-56 : ils passent au statut Emprunté, leur statut précédent
 * est conservé. RG-58 : un emprunt (bien d'un tiers) n'a ni fiche ni numéro patrimonial, il reçoit une référence EXT-.
 */
class CreateLoan
{
    use GuardsOperations;

    public const int MAX_ITEMS = 100;

    public function __construct(
        private OperationReferenceGenerator $references,
        private AttachDocuments $attachDocuments,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     * @param  iterable<int|string>  $assetIds
     * @param  array<int, int>  $expectedVersions  RG-86 : version lue à la sélection, par identifiant de bien
     * @param  iterable<UploadedFile>  $files  fiche de prêt signée, facultative
     */
    public function __invoke(
        array $data,
        iterable $assetIds = [],
        array $expectedVersions = [],
        iterable $files = [],
        ?DocumentType $documentType = null,
    ): Loan {
        $this->authorizeModule(PermissionModule::Loans, PermissionAbility::Create, 'Vous n\'avez pas le droit d\'enregistrer un prêt.');

        $direction = ($data['direction'] ?? null) instanceof LoanDirection ? $data['direction'] : LoanDirection::tryFrom((string) ($data['direction'] ?? ''));

        if ($direction === null) {
            throw ValidationException::withMessages(['direction' => 'Choisissez un prêt ou un emprunt.']);
        }

        $outOn = $this->date($data['out_on'] ?? null, 'out_on', 'La date de sortie est obligatoire.');
        $dueOn = $this->date($data['due_on'] ?? null, 'due_on', 'La date de retour prévue est obligatoire.', allowFuture: true);

        if ($dueOn->isBefore($outOn)) {
            throw ValidationException::withMessages(['due_on' => 'La date de retour prévue ne peut pas précéder la date de sortie.']);
        }

        $ids = collect($assetIds)->map(fn (mixed $id): int => (int) $id)->unique()->sort()->values();
        [$borrowerType, $borrowerName, $departmentId] = $this->borrower($direction, $data);
        $responsibleId = $this->responsible($data['responsible_id'] ?? auth()->id());
        $external = $direction === LoanDirection::In ? $this->external($data) : null;

        if ($direction === LoanDirection::Out) {
            if ($ids->isEmpty()) {
                throw ValidationException::withMessages(['assets' => 'Choisissez au moins un bien à prêter.']);
            }

            if ($ids->count() > self::MAX_ITEMS) {
                throw ValidationException::withMessages(['assets' => 'Un prêt ne peut pas dépasser '.self::MAX_ITEMS.' biens.']);
            }
        } elseif ($ids->isNotEmpty()) {
            throw ValidationException::withMessages(['assets' => 'Un emprunt ne concerne aucun bien du patrimoine.']);
        }

        $files = collect($files)->values();

        return DB::transaction(function () use ($direction, $ids, $outOn, $dueOn, $borrowerType, $borrowerName, $departmentId, $responsibleId, $external, $data, $expectedVersions, $files, $documentType): Loan {
            // Les biens d'abord (même ordre que les autres opérations), puis le compteur de références.
            $assets = $direction === LoanDirection::Out ? $this->lockAssets($ids) : collect();
            $this->assertLoanable($assets, $expectedVersions);

            $loan = Loan::query()->create([
                'reference' => $this->references->next($direction === LoanDirection::Out ? 'PRT' : 'EMP', $outOn->year),
                'direction' => $direction,
                'borrower_type' => $borrowerType,
                'borrower_name' => $borrowerName,
                'borrower_contact' => $this->clean($data['borrower_contact'] ?? null),
                'department_id' => $departmentId,
                'out_on' => $outOn->toDateString(),
                'due_on' => $dueOn->toDateString(),
                'responsible_id' => $responsibleId,
                'notes' => $this->clean($data['notes'] ?? null),
                'status' => LoanStatus::Ongoing,
            ]);

            foreach ($assets as $asset) {
                LoanItem::query()->create([
                    'loan_id' => $loan->getKey(),
                    'asset_id' => $asset->getKey(),
                    'condition_out_id' => $asset->asset_condition_id,
                    'previous_status' => $asset->status,
                ]);

                $previous = $asset->status;
                $asset->update(['status' => AssetStatus::OnLoan]);

                // RG-70 : prêt tracé, avec le statut avant et après.
                activity('prets')
                    ->performedOn($asset)
                    ->event('loaned')
                    ->withProperties([
                        'old' => ['status' => $previous->getLabel()],
                        'attributes' => [
                            'status' => AssetStatus::OnLoan->getLabel(),
                            'reference' => $loan->reference,
                            'borrower' => $borrowerName,
                            'due_on' => $dueOn->format('d/m/Y'),
                        ],
                    ])
                    ->log('Prêt d\'un bien');
            }

            if ($external !== null) {
                LoanItem::query()->create([
                    'loan_id' => $loan->getKey(),
                    'external_designation' => $external['designation'],
                    'external_reference' => $this->references->nextExternal(),
                    'external_value' => $external['value'],
                    'condition_out_id' => $external['condition_id'],
                ]);

                activity('prets')
                    ->performedOn($loan)
                    ->event('borrowed')
                    ->withProperties(['attributes' => [
                        'reference' => $loan->reference,
                        'borrower' => $borrowerName,
                        'due_on' => $dueOn->format('d/m/Y'),
                    ]])
                    ->log('Emprunt d\'un bien de tiers');
            }

            if ($files->isNotEmpty() && $documentType instanceof DocumentType && $assets->isNotEmpty()) {
                ($this->attachDocuments)($files, $documentType, $assets);
            }

            return $loan->load('items');
        }, attempts: 3);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{0: BorrowerType, 1: string, 2: int|null}
     */
    private function borrower(LoanDirection $direction, array $data): array
    {
        if ($direction === LoanDirection::In) {
            $user = auth()->user();

            // QL8-1 : un emprunt n'a ni site ni département, il reste invisible d'un utilisateur au périmètre restreint.
            if ($user instanceof User && ($user->perimeterSiteIds() !== null || $user->perimeterDepartmentIds() !== null)) {
                throw ValidationException::withMessages(['direction' => 'Un emprunt ne peut être enregistré que par un utilisateur sans périmètre restreint.']);
            }

            $name = $this->clean($data['borrower_name'] ?? null);

            if ($name === null) {
                throw ValidationException::withMessages(['borrower_name' => 'Le nom du prêteur est obligatoire.']);
            }

            return [BorrowerType::External, $name, null];
        }

        $type = ($data['borrower_type'] ?? null) instanceof BorrowerType ? $data['borrower_type'] : BorrowerType::tryFrom((string) ($data['borrower_type'] ?? ''));

        if ($type === null) {
            throw ValidationException::withMessages(['borrower_type' => 'Choisissez un bénéficiaire interne ou externe.']);
        }

        if ($type === BorrowerType::Internal) {
            $department = filled($data['department_id'] ?? null) ? Department::query()->find($data['department_id']) : null;

            if ($department === null || ! $department->is_active) {
                throw ValidationException::withMessages(['department_id' => 'Choisissez un département bénéficiaire actif de votre périmètre.']);
            }

            return [$type, $department->name, $department->getKey()];
        }

        $name = $this->clean($data['borrower_name'] ?? null);

        if ($name === null) {
            throw ValidationException::withMessages(['borrower_name' => 'Le nom du bénéficiaire est obligatoire.']);
        }

        return [$type, $name, null];
    }

    private function responsible(mixed $id): int
    {
        $user = filled($id) ? User::query()->where('is_active', true)->find($id) : null;

        if ($user === null) {
            throw ValidationException::withMessages(['responsible_id' => 'Choisissez un responsable de l\'opération parmi les comptes actifs.']);
        }

        return $user->getKey();
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{designation: string, value: int|null, condition_id: int}
     */
    private function external(array $data): array
    {
        $designation = $this->clean($data['external_designation'] ?? null);

        if ($designation === null) {
            throw ValidationException::withMessages(['external_designation' => 'La désignation du bien emprunté est obligatoire.']);
        }

        $condition = filled($data['condition_out_id'] ?? null) ? AssetCondition::query()->where('is_active', true)->find($data['condition_out_id']) : null;

        if ($condition === null) {
            throw ValidationException::withMessages(['condition_out_id' => 'Choisissez l\'état du bien à la réception.']);
        }

        return [
            'designation' => mb_substr($designation, 0, 255),
            'value' => $this->money($data['external_value'] ?? null, 'external_value'),
            'condition_id' => $condition->getKey(),
        ];
    }

    /**
     * @param  Collection<int, Asset>  $assets
     * @param  array<int, int>  $expectedVersions
     */
    private function assertLoanable(Collection $assets, array $expectedVersions): void
    {
        $disposed = $assets->filter(fn (Asset $asset): bool => $asset->isDisposed());

        if ($disposed->isNotEmpty()) {
            throw ValidationException::withMessages([
                'assets' => 'Un bien sorti du patrimoine ne peut pas être prêté : '.$disposed->pluck('number')->join(', ').'.',
            ]);
        }

        foreach ($assets as $asset) {
            $this->assertVersion($asset, $expectedVersions[$asset->getKey()] ?? null);
        }

        // RG-55 : seuls les biens En service ou En stock sont disponibles.
        $unavailable = $assets->filter(fn (Asset $asset): bool => ! in_array($asset->status, [AssetStatus::InService, AssetStatus::InStock], true));

        if ($unavailable->isNotEmpty()) {
            throw ValidationException::withMessages([
                'assets' => 'Ces biens ne sont pas disponibles : '
                    .$unavailable->map(fn (Asset $asset): string => $asset->number.' ('.$asset->status->getLabel().')')->join(', ').'.',
            ]);
        }
    }
}
