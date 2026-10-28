<?php

namespace App\Actions\Disposals;

use App\Actions\Depreciation\SyncDepreciationSchedule;
use App\Actions\Documents\AttachDocuments;
use App\Enums\AssetStatus;
use App\Enums\DisposalReason;
use App\Enums\PermissionAbility;
use App\Enums\PermissionModule;
use App\Exceptions\ConcurrentModificationException;
use App\Models\Asset;
use App\Models\Disposal;
use App\Models\DocumentType;
use App\Models\FiscalYear;
use App\Models\User;
use App\Services\DisposalReferenceGenerator;
use App\Services\DisposalValuation;
use App\Services\DocumentRules;
use App\Services\FiscalYearCalendar;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Sortie d'un bien du patrimoine (E26), en une seule transaction.
 *
 * - RG-51 : date, motif, décideur, référence de la décision, observations, justificatifs ; acquéreur
 *   et prix pour une cession (bénéficiaire pour un don).
 * - RG-52 : statut Cédé ou Réformé, date de sortie, fiche en lecture seule.
 * - RG-39 / A5 : plan arrêté à la date de sortie incluse ; valeur d'origine, cumul et VNC figés.
 * - RG-53 : confirmée par la saisie exacte du numéro du bien ; aucune annulation possible.
 * - RG-54 : traitement comptable « à valider ».
 * - RG-36 : refusée dans un exercice clôturé ou antérieur (Q9.12), aucune ligne figée ne bouge.
 * - RG-86 : refusée si la fiche a changé depuis l'ouverture de l'écran.
 */
class RecordDisposal
{
    public const int MAX_PRICE = 999_999_999_999;

    // Contrat avec le lot 9 : régularisation d'une ligne d'inventaire par la sortie.
    private const string INVENTORY_RESOLVER = 'App\\Actions\\Inventories\\ResolveInventoryLine';

    private const string INVENTORY_LINE_MODEL = 'App\\Models\\InventoryLine';

    public function __construct(
        private SyncDepreciationSchedule $sync,
        private DisposalValuation $valuation,
        private DisposalReferenceGenerator $references,
        private AttachDocuments $attachDocuments,
    ) {}

    /**
     * @param  array{disposed_on?: mixed, reason?: mixed, decided_by?: mixed, decision_reference?: mixed, buyer_or_beneficiary?: mixed, sale_price?: mixed, notes?: mixed}  $data
     * @param  string|null  $confirmation  RG-53 : numéro du bien saisi par l'utilisateur
     * @param  int|null  $expectedVersion  RG-86 : version de la fiche lue à l'ouverture de l'écran
     * @param  iterable<UploadedFile>  $files  justificatifs, rattachés au bien et à la sortie
     */
    public function __invoke(
        Asset|int $asset,
        array $data,
        ?string $confirmation,
        ?int $expectedVersion = null,
        iterable $files = [],
        ?DocumentType $documentType = null,
        ?int $inventoryLineId = null,
    ): Disposal {
        $user = auth()->user();

        if ($user instanceof User && ! ($user->is_active && $user->can(PermissionModule::Disposals->permission(PermissionAbility::Create)))) {
            throw new AuthorizationException('Vous n\'avez pas le droit d\'enregistrer une sortie du patrimoine.');
        }

        $assetId = $asset instanceof Asset ? (int) $asset->getKey() : $asset;
        $values = $this->validated($data);
        $files = collect($files)->values();

        if ($files->isNotEmpty() && ! $documentType instanceof DocumentType) {
            throw ValidationException::withMessages(['document_type_id' => 'Choisissez le type des justificatifs.']);
        }

        $stored = [];

        try {
            return DB::transaction(function () use ($assetId, $values, $confirmation, $expectedVersion, $files, $documentType, $inventoryLineId, &$stored): Disposal {
                /** @var CarbonImmutable $disposedOn */
                $disposedOn = $values['disposed_on'];
                /** @var DisposalReason $reason */
                $reason = $values['reason'];

                // RG-36 : la clôture verrouille l'exercice ; on le lit sous verrou partagé pour ne pas croiser une clôture.
                FiscalYear::query()->where('year', $disposedOn->year)->sharedLock()->first();
                $this->assertOpenYear($disposedOn, new FiscalYearCalendar);

                // RG-81 : le scope du bien s'applique, un bien hors périmètre n'est pas trouvé.
                $current = Asset::query()->whereKey($assetId)->lockForUpdate()->first();

                if (! $current instanceof Asset) {
                    throw ValidationException::withMessages(['asset_id' => 'Ce bien est introuvable ou hors de votre périmètre.']);
                }

                $this->assertDisposable($current, $disposedOn, $confirmation, $expectedVersion);

                $documents = collect();

                if ($files->isNotEmpty() && $documentType instanceof DocumentType) {
                    $documents = ($this->attachDocuments)($files, $documentType, [$current]);
                    array_push($stored, ...$documents->pluck('path')->all());
                }

                $previousStatus = $current->status;
                $current->forceFill(['status' => $reason->resultingStatus(), 'disposed_at' => $disposedOn->toDateString()]);
                // Le journal de la sortie (ci-dessous) remplace l'entrée automatique « Modification d'une fiche ».
                activity()->withoutLogs(fn () => $current->save());

                // RG-39 : plan arrêté à la date de sortie incluse ; les lignes figées ne bougent pas (RG-36).
                try {
                    ($this->sync)($current);
                } catch (ValidationException $exception) {
                    throw ValidationException::withMessages([
                        'disposed_on' => 'Le plan d\'amortissement de ce bien ne peut pas être arrêté à cette date : '
                            .collect($exception->errors())->flatten()->implode(' '),
                    ]);
                }

                $schedule = SyncDepreciationSchedule::isEligible($current) ? $this->sync->currentSchedule($current) : null;
                $frozen = $this->valuation->fromSchedule($current, $schedule, $disposedOn);

                $disposal = Disposal::query()->create([
                    'reference' => $this->references->next($disposedOn->year),
                    'asset_id' => $current->getKey(),
                    'disposed_on' => $disposedOn->toDateString(),
                    'reason' => $reason,
                    'decided_by' => $values['decided_by'],
                    'decision_reference' => $values['decision_reference'],
                    'buyer_or_beneficiary' => $values['buyer_or_beneficiary'],
                    'sale_price' => $values['sale_price'],
                    'notes' => $values['notes'],
                    'original_value' => $frozen->originalValue,
                    'accumulated_depreciation' => $frozen->accumulatedDepreciation,
                    'net_book_value' => $frozen->netBookValue,
                    'previous_status' => $previousStatus,
                ]);

                if ($documents->isNotEmpty()) {
                    $disposal->documents()->attach($documents->pluck('id')->all());
                }

                $this->log($current, $disposal, $previousStatus);
                $this->resolveInventoryLine($inventoryLineId, $disposal);

                return $disposal;
            }, attempts: 5);
        } catch (UniqueConstraintViolationException) {
            $this->forget($stored);

            throw ValidationException::withMessages(['asset_id' => 'Ce bien est déjà sorti du patrimoine.']);
        } catch (Throwable $exception) {
            $this->forget($stored);

            throw $exception;
        }
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{disposed_on: CarbonImmutable, reason: DisposalReason, decided_by: string, decision_reference: string|null, buyer_or_beneficiary: string|null, sale_price: int|null, notes: string|null}
     */
    private function validated(array $data): array
    {
        $errors = [];
        $reasonValue = $data['reason'] ?? null;
        $reason = $reasonValue instanceof DisposalReason ? $reasonValue : DisposalReason::tryFrom(is_scalar($reasonValue) ? (string) $reasonValue : '');

        if ($reason === null) {
            $errors['reason'] = 'Choisissez le motif de la sortie.';
        }

        $disposedOn = null;
        $date = $data['disposed_on'] ?? null;

        if (blank($date) || ! (is_string($date) || $date instanceof \DateTimeInterface)) {
            $errors['disposed_on'] = 'La date de sortie est obligatoire.';
        } else {
            try {
                $disposedOn = CarbonImmutable::parse($date)->startOfDay();
            } catch (Throwable) {
                $errors['disposed_on'] = 'La date de sortie n\'est pas valide.';
            }
        }

        if ($disposedOn !== null && $disposedOn->isAfter(CarbonImmutable::today())) {
            $errors['disposed_on'] = 'La date de sortie ne peut pas être dans le futur.';
        }

        $decidedBy = $this->text($data['decided_by'] ?? null);
        $decisionReference = $this->text($data['decision_reference'] ?? null);
        $counterparty = $this->text($data['buyer_or_beneficiary'] ?? null);
        $notes = $this->text($data['notes'] ?? null, 5000);

        if ($decidedBy === null) {
            $errors['decided_by'] = 'Indiquez qui a pris la décision de sortie.';
        }

        foreach (['decided_by' => $data['decided_by'] ?? null, 'decision_reference' => $data['decision_reference'] ?? null, 'buyer_or_beneficiary' => $data['buyer_or_beneficiary'] ?? null] as $field => $value) {
            if (is_string($value) && mb_strlen(trim($value)) > 255) {
                $errors[$field] = 'Ce champ ne doit pas dépasser 255 caractères.';
            }
        }

        if (is_string($data['notes'] ?? null) && mb_strlen(trim($data['notes'])) > 5000) {
            $errors['notes'] = 'Les observations ne doivent pas dépasser 5 000 caractères.';
        }

        $salePrice = null;

        if ($reason?->requiresCounterparty() && $counterparty === null) {
            $errors['buyer_or_beneficiary'] = $reason === DisposalReason::Donation ? 'Indiquez le bénéficiaire du don.' : 'Indiquez l\'acquéreur.';
        }

        if ($reason?->requiresPrice()) {
            $salePrice = $this->amount($data['sale_price'] ?? null);

            if ($salePrice === null) {
                $errors['sale_price'] = 'Indiquez le prix de cession, en francs entiers (0 ou plus, au plus '.Money::format(self::MAX_PRICE).').';
            }
        }

        if ($errors !== [] || $reason === null || $disposedOn === null || $decidedBy === null) {
            throw ValidationException::withMessages($errors);
        }

        return [
            'disposed_on' => $disposedOn,
            'reason' => $reason,
            'decided_by' => $decidedBy,
            'decision_reference' => $decisionReference,
            'buyer_or_beneficiary' => $reason->requiresCounterparty() ? $counterparty : null,
            'sale_price' => $salePrice,
            'notes' => $notes,
        ];
    }

    /**
     * Q9.12, option prudente : une sortie ne se date pas dans un exercice clôturé ni antérieur au
     * premier exercice recalculable, dont les dotations sont figées (RG-36).
     */
    private function assertOpenYear(CarbonImmutable $disposedOn, FiscalYearCalendar $calendar): void
    {
        $first = $calendar->firstRecalculableYear();

        if ($first !== null && $disposedOn->year < $first) {
            $fiscalYear = $calendar->find($disposedOn->year);

            throw ValidationException::withMessages([
                'disposed_on' => ($fiscalYear?->isClosed() ? 'L\'exercice '.$disposedOn->year.' est clôturé' : 'L\'année '.$disposedOn->year.' est antérieure aux exercices ouverts')
                    .' : ses dotations sont figées et une sortie ne peut pas y être datée (RG-36). Voyez avec le comptable (question Q9.12).',
            ]);
        }
    }

    private function assertDisposable(Asset $asset, CarbonImmutable $disposedOn, ?string $confirmation, ?int $expectedVersion): void
    {
        // RG-86 : la fiche a changé depuis l'ouverture de l'écran (valeurs affichées périmées).
        if ($expectedVersion !== null && $asset->lock_version !== $expectedVersion) {
            throw new ConcurrentModificationException(
                $asset->updated_by ? User::query()->find($asset->updated_by) : null,
                $asset->updated_at,
            );
        }

        if ($asset->isDisposed() || Disposal::query()->withoutGlobalScopes()->where('asset_id', $asset->getKey())->exists()) {
            throw ValidationException::withMessages(['asset_id' => 'Ce bien est déjà sorti du patrimoine.']);
        }

        // QL10-1, option prudente : on clôture d'abord le prêt ou l'intervention en cours.
        if (in_array($asset->status, [AssetStatus::OnLoan, AssetStatus::InMaintenance], true)) {
            throw ValidationException::withMessages([
                'asset_id' => 'Ce bien est '.mb_strtolower($asset->status->getLabel()).' : enregistrez d\'abord '
                    .($asset->status === AssetStatus::OnLoan ? 'son retour de prêt' : 'la clôture de l\'intervention').', puis sa sortie.',
            ]);
        }

        if ($disposedOn->lt(CarbonImmutable::parse($asset->acquired_on->toDateString()))) {
            throw ValidationException::withMessages(['disposed_on' => 'La date de sortie précède la date d\'acquisition du bien ('.$asset->acquired_on->format('d/m/Y').').']);
        }

        if ($asset->in_service_on !== null && $disposedOn->lt(CarbonImmutable::parse($asset->in_service_on->toDateString()))) {
            throw ValidationException::withMessages(['disposed_on' => 'La date de sortie précède la mise en service du bien ('.$asset->in_service_on->format('d/m/Y').').']);
        }

        // RG-53 : confirmation explicite par la saisie exacte du numéro du bien.
        if ($confirmation === null || trim($confirmation) !== $asset->number) {
            throw ValidationException::withMessages(['confirmation' => 'Saisissez exactement le numéro du bien ('.$asset->number.') pour confirmer la sortie.']);
        }
    }

    /**
     * RG-70 : sortie tracée avec le statut avant et après et les valeurs figées.
     */
    private function log(Asset $asset, Disposal $disposal, AssetStatus $previousStatus): void
    {
        activity('sorties')
            ->performedOn($asset)
            ->event('disposed')
            ->withProperties([
                'old' => ['status' => $previousStatus->getLabel(), 'disposed_at' => null],
                'attributes' => array_filter([
                    'status' => $asset->status->getLabel(),
                    'disposed_at' => $disposal->disposed_on->format('d/m/Y'),
                    'reference' => $disposal->reference,
                    'reason' => $disposal->reason->getLabel(),
                    'decided_by' => $disposal->decided_by,
                    'decision_reference' => $disposal->decision_reference,
                    'buyer_or_beneficiary' => $disposal->buyer_or_beneficiary,
                    'sale_price' => $disposal->sale_price,
                    'original_value' => $disposal->original_value,
                    'accumulated_depreciation' => $disposal->accumulated_depreciation,
                    'net_book_value' => $disposal->net_book_value,
                    'accounting_status' => $disposal->accounting_status->getLabel(),
                ], fn (mixed $value): bool => $value !== null),
            ])
            ->log('Sortie du patrimoine');
    }

    private function resolveInventoryLine(?int $inventoryLineId, Disposal $disposal): void
    {
        $resolver = self::INVENTORY_RESOLVER;

        if ($inventoryLineId === null || ! class_exists($resolver)) {
            return;
        }

        $lineModel = self::INVENTORY_LINE_MODEL;
        $line = class_exists($lineModel) ? call_user_func([$lineModel, 'query'])->findOrFail($inventoryLineId) : $inventoryLineId;
        $instance = app()->make($resolver);

        if (is_callable($instance)) {
            $instance($line, 'disposal', $disposal);
        }
    }

    private function text(mixed $value, int $max = 255): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : mb_substr($value, 0, $max);
    }

    // Montants en francs entiers (CLAUDE.md) : chiffres seuls, espaces de milliers tolérés.
    private function amount(mixed $value): ?int
    {
        if (is_int($value)) {
            $amount = $value;
        } elseif (is_string($value) && preg_match('/^\d+$/', $digits = str_replace([' ', "\u{00A0}", "\u{202F}"], '', trim($value))) === 1 && strlen($digits) <= 13) {
            $amount = (int) $digits;
        } else {
            return null;
        }

        return $amount >= 0 && $amount <= self::MAX_PRICE ? $amount : null;
    }

    /**
     * @param  list<string>  $paths
     */
    private function forget(array $paths): void
    {
        if ($paths !== []) {
            Storage::disk(DocumentRules::DISK)->delete($paths);
        }
    }
}
