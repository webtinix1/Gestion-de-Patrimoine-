<?php

namespace App\Actions\Inventories;

use App\Enums\InventoryLineResult;
use App\Enums\InventoryStatus;
use App\Exceptions\InventoryLineConflictException;
use App\Models\Asset;
use App\Models\AssetCondition;
use App\Models\Department;
use App\Models\Inventory;
use App\Models\InventoryLine;
use App\Models\Room;
use App\Models\Site;
use App\Models\User;
use App\Services\Inventories\InventoryJournal;
use App\Services\Inventories\ScanOutcome;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Saisie d'un inventaire (E20), enregistrée au fil de l'eau (RG-47).
 *
 * Plusieurs personnes saisissent en même temps : chaque saisie verrouille la ligne de l'inventaire
 * (verrou exclusif, toujours pris en premier, puis la ligne), ce qui sérialise les écritures d'un même
 * inventaire sans interblocage. Règle retenue pour deux saisies du même bien :
 *
 * - une lecture à la douchette n'écrase jamais un résultat déjà saisi : même résultat, rien ne change
 *   (« déjà pointé par… ») ; résultat différent, rien n'est écrit et le conflit est affiché ;
 * - un bouton (Présent, Manquant, Autre affectation, état) porte la version de la ligne affichée :
 *   si quelqu'un a pointé la ligne entre-temps, la saisie est refusée et la ligne à jour est montrée.
 *   Après rechargement, la correction est possible et tracée (avant et après) ;
 * - un bien hors liste n'a qu'une ligne par inventaire (index unique), même scanné sur deux postes.
 */
class RecordInventoryEntry
{
    public const array INTENTS = ['present', 'missing', 'other_assignment', 'condition', 'note'];

    public function __construct(private InventoryJournal $journal) {}

    /**
     * RG-45, RG-46 : lecture d'un code (douchette : code + Entrée).
     */
    public function scan(Inventory $inventory, User $user, string $code, ?int $roomId = null): ScanOutcome
    {
        $code = mb_strtoupper(trim($code));

        if ($code === '') {
            throw ValidationException::withMessages(['code' => 'Scannez l\'étiquette ou saisissez un numéro.']);
        }

        $room = $this->room($roomId, 'room_id');

        return DB::transaction(function () use ($inventory, $user, $code, $room): ScanOutcome {
            $current = $this->lockInventory($inventory, $user);

            // RG-81 : un bien hors périmètre est introuvable.
            $asset = Asset::query()->where('number', $code)->first();

            if (! $asset instanceof Asset) {
                return new ScanOutcome(ScanOutcome::UNKNOWN, $code, 'Aucun bien avec ce code dans votre périmètre. S\'il n\'a pas d\'étiquette, signalez-le comme bien trouvé non enregistré.');
            }

            $line = InventoryLine::query()->withoutGlobalScopes()
                ->where('inventory_id', $current->id)
                ->where('asset_id', $asset->id)
                ->lockForUpdate()
                ->first();

            if (! $line instanceof InventoryLine) {
                return $this->recordOutOfScope($current, $user, $asset, $room);
            }

            $result = match (true) {
                $line->result === InventoryLineResult::OutOfScope => InventoryLineResult::OutOfScope,
                $room !== null && $line->expected_room_id !== null && $room->id !== $line->expected_room_id => InventoryLineResult::OtherAssignment,
                default => InventoryLineResult::Present,
            };

            if ($line->result === InventoryLineResult::Pending) {
                $before = $this->journal->snapshot($line);
                $line->forceFill([
                    'result' => $result,
                    'found_room_id' => $room?->id,
                    'found_site_id' => $room?->site_id,
                    'found_department_id' => null,
                    'checked_by' => $user->id,
                    'checked_at' => now(),
                ])->save();
                $this->journal->line($line, $current, $before, $user);

                $message = $result === InventoryLineResult::Present
                    ? 'présent, affectation conforme'
                    : 'présent, mais enregistré dans « '.(Room::query()->withoutGlobalScopes()->find($line->expected_room_id)->name ?? '—').' » : autre affectation';

                return new ScanOutcome(ScanOutcome::RECORDED, $code, $asset->number.' '.$asset->label().' · '.$message, $line);
            }

            $by = ($line->checked_by ? User::query()->find($line->checked_by)?->fullName() : null) ?? 'l\'application';
            $at = $line->checked_at?->format('H:i') ?? '—';

            if ($line->result === $result) {
                return new ScanOutcome(ScanOutcome::ALREADY, $code, $asset->number.' déjà pointé « '.$line->result->getLabel().' » par '.$by.' à '.$at.'.', $line);
            }

            // RG-47 : la lecture ne remplace pas un résultat différent déjà saisi.
            $reason = $line->result === InventoryLineResult::Justified
                ? 'justifié d\'office (prêté ou en maintenance au démarrage)'
                : 'pointé « '.$line->result->getLabel().' » par '.$by.' à '.$at;

            return new ScanOutcome(ScanOutcome::CONFLICT, $code, $asset->number.' est déjà '.$reason.'. Rien n\'a été modifié : corrigez la ligne avec ses boutons si nécessaire.', $line);
        }, attempts: 5);
    }

    /**
     * Boutons de la ligne : Présent, Manquant, Autre affectation, état constaté, observation.
     *
     * @param  array{room_id?: mixed, department_id?: mixed, condition_id?: mixed}  $found
     * @param  int  $expectedVersion  RG-47 : version de la ligne affichée, toujours exigée
     */
    public function mark(Inventory $inventory, User $user, int $lineId, string $intent, int $expectedVersion, array $found = [], ?string $notes = null): InventoryLine
    {
        if (! in_array($intent, self::INTENTS, true)) {
            throw ValidationException::withMessages(['intent' => 'Saisie inconnue.']);
        }

        $room = $this->room(filled($found['room_id'] ?? null) ? (int) $found['room_id'] : null, 'room_id', scoped: false);
        $departmentId = filled($found['department_id'] ?? null) ? (int) $found['department_id'] : null;
        $conditionId = filled($found['condition_id'] ?? null) ? (int) $found['condition_id'] : null;

        if ($departmentId !== null && ! Department::query()->withoutGlobalScopes()->whereKey($departmentId)->exists()) {
            throw ValidationException::withMessages(['department_id' => 'Ce département n\'existe pas.']);
        }

        if ($conditionId !== null && ! AssetCondition::query()->active()->whereKey($conditionId)->exists()) {
            throw ValidationException::withMessages(['condition_id' => 'Cet état n\'existe pas.']);
        }

        if ($intent === 'condition' && $conditionId === null) {
            throw ValidationException::withMessages(['condition_id' => 'Choisissez l\'état constaté.']);
        }

        return DB::transaction(function () use ($inventory, $user, $lineId, $intent, $room, $departmentId, $conditionId, $expectedVersion, $notes): InventoryLine {
            $current = $this->lockInventory($inventory, $user);

            // RG-81 : la ligne doit être dans le périmètre de l'utilisateur.
            $line = InventoryLine::query()->where('inventory_id', $current->id)->whereKey($lineId)->lockForUpdate()->first();

            if (! $line instanceof InventoryLine) {
                throw ValidationException::withMessages(['line' => 'Cette ligne est introuvable dans votre périmètre.']);
            }

            if ($line->lock_version !== $expectedVersion) {
                throw new InventoryLineConflictException(
                    $line->asset_id ? (Asset::query()->withoutGlobalScopes()->find($line->asset_id)->number ?? '—') : 'Ce bien',
                    $line->result,
                    $line->checked_by ? User::query()->find($line->checked_by) : null,
                    $line->checked_at,
                );
            }

            $outsideList = in_array($line->result, [InventoryLineResult::OutOfScope, InventoryLineResult::Unregistered], true);

            if ($outsideList && ! in_array($intent, ['condition', 'note'], true)) {
                throw ValidationException::withMessages(['line' => 'Ce bien n\'est pas dans la liste attendue : seuls son état constaté et ses observations se modifient.']);
            }

            $values = match ($intent) {
                'present' => [
                    'result' => $this->presentResult($line, $line->found_condition_id),
                    'found_room_id' => null, 'found_site_id' => null, 'found_department_id' => null,
                ],
                'missing' => [
                    'result' => InventoryLineResult::Missing,
                    'found_room_id' => null, 'found_site_id' => null, 'found_department_id' => null, 'found_condition_id' => null,
                ],
                'other_assignment' => $this->otherAssignment($line, $room, $departmentId),
                'note' => [],
                default => [
                    'found_condition_id' => $conditionId,
                    'result' => $outsideList || $line->result === InventoryLineResult::OtherAssignment
                        ? $line->result
                        : $this->presentResult($line, $conditionId),
                ],
            };

            if ($notes !== null) {
                $values['notes'] = trim($notes) === '' ? null : trim($notes);
            }

            $line->forceFill($values);

            if (! $line->isDirty()) {
                return $line;
            }

            $before = [];

            foreach (array_keys($this->journal->snapshot($line)) as $key) {
                $before[$key] = $line->getOriginal($key);
            }

            // Une observation seule ne vaut pas pointage.
            $line->forceFill($intent === 'note' ? [] : ['checked_by' => $user->id, 'checked_at' => now()])->save();
            $this->journal->line($line, $current, $before, $user);

            return $line;
        }, attempts: 5);
    }

    /**
     * RG-46 : bien trouvé sans fiche, enregistré « non enregistré » avec une description.
     *
     * @param  array<string, mixed>  $data
     */
    public function addUnregistered(Inventory $inventory, User $user, array $data): InventoryLine
    {
        $description = trim((string) ($data['description'] ?? ''));
        $errors = [];

        if ($description === '') {
            $errors['description'] = 'Décrivez le bien trouvé (nature, marque, signes distinctifs).';
        } elseif (mb_strlen($description) > 500) {
            $errors['description'] = 'La description ne dépasse pas 500 caractères.';
        }

        $siteId = filled($data['site_id'] ?? null) ? (int) $data['site_id'] : null;
        $departmentId = filled($data['department_id'] ?? null) ? (int) $data['department_id'] : null;
        $conditionId = filled($data['condition_id'] ?? null) ? (int) $data['condition_id'] : null;
        $room = $this->room(filled($data['room_id'] ?? null) ? (int) $data['room_id'] : null, 'room_id');

        // RG-81 : le bien trouvé est rattaché à un site et un département du périmètre de l'utilisateur.
        if ($siteId === null || ! Site::query()->whereKey($siteId)->exists()) {
            $errors['site_id'] = 'Choisissez un site de votre périmètre.';
        }

        if ($departmentId === null || ! Department::query()->whereKey($departmentId)->exists()) {
            $errors['department_id'] = 'Choisissez un département de votre périmètre.';
        }

        if ($room !== null && $room->site_id !== $siteId) {
            $errors['room_id'] = 'Ce local n\'appartient pas au site choisi.';
        }

        if ($conditionId !== null && ! AssetCondition::query()->active()->whereKey($conditionId)->exists()) {
            $errors['condition_id'] = 'Cet état n\'existe pas.';
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        $notes = trim((string) ($data['notes'] ?? ''));

        return DB::transaction(function () use ($inventory, $user, $description, $siteId, $departmentId, $conditionId, $room, $notes): InventoryLine {
            $current = $this->lockInventory($inventory, $user);

            $line = new InventoryLine;
            $line->forceFill([
                'inventory_id' => $current->id,
                'asset_id' => null,
                'expected' => false,
                'result' => InventoryLineResult::Unregistered,
                'description' => $description,
                'found_site_id' => $siteId,
                'found_department_id' => $departmentId,
                'found_room_id' => $room?->id,
                'found_condition_id' => $conditionId,
                'notes' => $notes === '' ? null : $notes,
                'checked_by' => $user->id,
                'checked_at' => now(),
                'created_by' => $user->id,
            ])->save();

            $this->journal->line($line, $current, [], $user, 'Bien non enregistré signalé dans l\'inventaire');

            return $line;
        }, attempts: 5);
    }

    private function lockInventory(Inventory $inventory, User $user): Inventory
    {
        // RG-47 : verrou exclusif de l'inventaire, pris en premier par toute saisie et par la clôture.
        $current = Inventory::query()->lockForUpdate()->find($inventory->getKey());

        if (! $current instanceof Inventory) {
            throw new AuthorizationException('Inventaire introuvable.');
        }

        if ($current->status !== InventoryStatus::InProgress) {
            throw ValidationException::withMessages(['inventory' => 'La saisie de '.$current->reference.' est terminée ('.$current->status->getLabel().') : plus aucune saisie n\'est possible.']);
        }

        if (! $user->can('enter', $current)) {
            throw new AuthorizationException('Vous n\'êtes pas désigné pour saisir cet inventaire.');
        }

        return $current;
    }

    private function recordOutOfScope(Inventory $inventory, User $user, Asset $asset, ?Room $room): ScanOutcome
    {
        // RG-46 : bien scanné hors de la liste attendue.
        $line = new InventoryLine;
        $line->forceFill([
            'inventory_id' => $inventory->id,
            'asset_id' => $asset->id,
            'expected' => false,
            'expected_site_id' => $asset->site_id,
            'expected_room_id' => $asset->room_id,
            'expected_department_id' => $asset->department_id,
            'expected_condition_id' => $asset->asset_condition_id,
            'expected_status' => $asset->status,
            'result' => InventoryLineResult::OutOfScope,
            'found_room_id' => $room?->id,
            'found_site_id' => $room?->site_id,
            'checked_by' => $user->id,
            'checked_at' => now(),
            'created_by' => $user->id,
        ])->save();

        $this->journal->line($line, $inventory, [], $user);

        return new ScanOutcome(
            ScanOutcome::OUT_OF_SCOPE,
            $asset->number,
            $asset->number.' '.$asset->label().' n\'est pas dans la liste attendue'.($asset->isDisposed() ? ' (sorti du patrimoine)' : '').' : enregistré « présent hors périmètre ».',
            $line,
        );
    }

    private function presentResult(InventoryLine $line, ?int $conditionId): InventoryLineResult
    {
        return $conditionId !== null && $line->expected_condition_id !== null && $conditionId !== $line->expected_condition_id
            ? InventoryLineResult::ConditionMismatch
            : InventoryLineResult::Present;
    }

    /**
     * RG-45 : présent mais affecté ailleurs (autre département ou autre local).
     *
     * @return array<string, mixed>
     */
    private function otherAssignment(InventoryLine $line, ?Room $room, ?int $departmentId): array
    {
        // QL9-12 : le constat se fait vers le périmètre de l'utilisateur ou l'affectation attendue de la ligne.
        if ($departmentId !== null && $departmentId !== $line->expected_department_id
            && ! Department::query()->whereKey($departmentId)->exists()) {
            throw ValidationException::withMessages(['department_id' => 'Choisissez un département de votre périmètre ou le département attendu.']);
        }

        if ($room !== null && $room->id !== $line->expected_room_id && ! Room::query()->whereKey($room->id)->exists()) {
            throw ValidationException::withMessages(['room_id' => 'Choisissez un local de votre périmètre ou le local attendu.']);
        }

        $roomDiffers = $room !== null && $room->id !== $line->expected_room_id;
        $departmentDiffers = $departmentId !== null && $departmentId !== $line->expected_department_id;

        if (! $roomDiffers && ! $departmentDiffers) {
            throw ValidationException::withMessages([
                'department_id' => 'Indiquez le département ou le local où le bien a été trouvé, différent de son affectation enregistrée.',
            ]);
        }

        return [
            'result' => InventoryLineResult::OtherAssignment,
            'found_room_id' => $room?->id,
            'found_site_id' => $room?->site_id,
            'found_department_id' => $departmentDiffers ? $departmentId : null,
        ];
    }

    private function room(?int $roomId, string $field, bool $scoped = true): ?Room
    {
        if ($roomId === null) {
            return null;
        }

        $query = $scoped ? Room::query() : Room::query()->withoutGlobalScopes();
        $room = $query->find($roomId);

        if (! $room instanceof Room) {
            throw ValidationException::withMessages([$field => 'Ce local n\'existe pas ou n\'est pas dans votre périmètre.']);
        }

        return $room;
    }
}
