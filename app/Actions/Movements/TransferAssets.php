<?php

namespace App\Actions\Movements;

use App\Actions\Documents\AttachDocuments;
use App\Enums\PermissionAbility;
use App\Enums\PermissionModule;
use App\Exceptions\ConcurrentModificationException;
use App\Models\Asset;
use App\Models\Department;
use App\Models\DocumentType;
use App\Models\Holder;
use App\Models\Movement;
use App\Models\Room;
use App\Models\Service;
use App\Models\Site;
use App\Models\User;
use App\Services\MovementRecorder;
use Carbon\CarbonInterface;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * RG-21, RG-23 : transfère un ou plusieurs biens vers une nouvelle affectation. Un mouvement par bien,
 * l'affectation précédente est conservée dans le mouvement, tout ou rien.
 */
class TransferAssets
{
    public function __construct(
        private MovementRecorder $recorder,
        private AttachDocuments $attachDocuments,
    ) {}

    /**
     * @param  iterable<Asset|int>  $assets
     * @param  array{site_id: int|string|null, department_id: int|string|null, service_id?: int|string|null, room_id?: int|string|null, holder_id?: int|string|null, moved_on: CarbonInterface|string|null, reason?: string|null, notes?: string|null}  $data
     * @param  array<int, int>  $expectedVersions  RG-86 : version lue à l'ouverture, par identifiant de bien
     * @param  iterable<UploadedFile>  $files  justificatif facultatif, rattaché aux biens transférés
     * @return Collection<int, Movement>
     */
    public function __invoke(
        iterable $assets,
        array $data,
        array $expectedVersions = [],
        iterable $files = [],
        ?DocumentType $documentType = null,
    ): Collection {
        $user = auth()->user();

        if ($user instanceof User && ! $user->can(PermissionModule::Movements->permission(PermissionAbility::Create))) {
            throw new AuthorizationException('Vous n\'avez pas le droit de transférer des biens.');
        }

        $ids = collect($assets)
            ->map(fn (Asset|int $asset): int => $asset instanceof Asset ? (int) $asset->getKey() : $asset)
            ->unique()
            ->sort()
            ->values();

        if ($ids->isEmpty()) {
            throw ValidationException::withMessages(['assets' => 'Choisissez au moins un bien à transférer.']);
        }

        $movedOn = $this->movedOn($data['moved_on'] ?? null);
        $target = $this->target($data);
        $reason = $this->clean($data['reason'] ?? null);
        $notes = $this->clean($data['notes'] ?? null);
        $files = collect($files)->values();

        return DB::transaction(function () use ($ids, $target, $movedOn, $reason, $notes, $expectedVersions, $files, $documentType): Collection {
            // RG-81 : le scope du bien s'applique, un bien hors périmètre n'est pas trouvé.
            $locked = Asset::query()->whereKey($ids->all())->orderBy('id')->lockForUpdate()->get();

            if ($locked->count() !== $ids->count()) {
                throw ValidationException::withMessages(['assets' => 'Certains biens sont introuvables ou hors de votre périmètre.']);
            }

            $this->assertTransferable($locked, $target, $expectedVersions);

            $batchUuid = $locked->count() > 1 ? (string) Str::uuid() : null;
            $movements = $locked->map(function (Asset $asset) use ($target, $movedOn, $reason, $notes, $batchUuid): Movement {
                $from = $this->recorder->assignmentOf($asset);

                // RG-86 : la mise à jour passe par le modèle, qui incrémente la version de la fiche.
                // Le journal du transfert (ci-dessous) remplace l'entrée automatique « Modification d'une fiche ».
                activity()->withoutLogs(fn () => $asset->update([...$target, 'assigned_since' => $movedOn->toDateString()]));

                $movement = $this->recorder->record($asset, $from, $target, $movedOn, $reason, $notes, $batchUuid);
                $this->log($asset, $movement, $from, $target);

                return $movement;
            })->values();

            if ($files->isNotEmpty() && $documentType instanceof DocumentType) {
                ($this->attachDocuments)($files, $documentType, $locked);
            }

            return $movements;
        }, attempts: 3);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, int|null>
     */
    private function target(array $data): array
    {
        $target = [];

        foreach (array_keys(MovementRecorder::ELEMENTS) as $element) {
            $value = $data[$element] ?? null;
            $target[$element] = filled($value) ? (int) $value : null;
        }

        $errors = [];

        if ($target['site_id'] === null) {
            $errors['site_id'] = 'Le site est obligatoire.';
        }

        if ($target['department_id'] === null) {
            $errors['department_id'] = 'Le département est obligatoire.';
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        $service = $target['service_id'] === null ? null : Service::query()->withoutGlobalScopes()->find($target['service_id']);
        $room = $target['room_id'] === null ? null : Room::query()->withoutGlobalScopes()->find($target['room_id']);

        $checks = [
            'site_id' => Site::query()->withoutGlobalScopes()->find($target['site_id']),
            'department_id' => Department::query()->withoutGlobalScopes()->find($target['department_id']),
            'service_id' => $service,
            'room_id' => $room,
            'holder_id' => $target['holder_id'] === null ? null : Holder::query()->find($target['holder_id']),
        ];

        foreach ($checks as $element => $model) {
            if ($target[$element] !== null && $model === null) {
                $errors[$element] = 'Cet élément n\'existe pas.';
            }
        }

        // Q60 : prudence, on ne transfère pas un bien vers un site ou un département hors du périmètre de l'utilisateur.
        foreach (['site_id' => Site::class, 'department_id' => Department::class] as $element => $class) {
            if ($checks[$element] !== null && ! $class::query()->whereKey($target[$element])->exists()) {
                $errors[$element] = 'Ce '.($element === 'site_id' ? 'site' : 'département').' est hors de votre périmètre : vous ne pouvez pas y transférer de bien.';
            }
        }

        // L'affectation reste cohérente : le service dépend du département, le local du site.
        if ($service !== null && (int) $service->department_id !== $target['department_id']) {
            $errors['service_id'] = 'Ce service n\'appartient pas au département choisi.';
        }

        if ($room !== null && (int) $room->site_id !== $target['site_id']) {
            $errors['room_id'] = 'Ce local n\'appartient pas au site choisi.';
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return $target;
    }

    private function movedOn(mixed $value): Carbon
    {
        if (blank($value)) {
            throw ValidationException::withMessages(['moved_on' => 'La date du transfert est obligatoire.']);
        }

        $date = Carbon::parse($value)->startOfDay();

        if ($date->isAfter(today())) {
            throw ValidationException::withMessages(['moved_on' => 'La date du transfert ne peut pas être dans le futur.']);
        }

        return $date;
    }

    /**
     * @param  Collection<int, Asset>  $assets
     * @param  array<string, int|null>  $target
     * @param  array<int, int>  $expectedVersions
     */
    private function assertTransferable(Collection $assets, array $target, array $expectedVersions): void
    {
        $disposed = $assets->filter(fn (Asset $asset): bool => $asset->isDisposed());

        if ($disposed->isNotEmpty()) {
            throw ValidationException::withMessages([
                'assets' => 'Un bien sorti du patrimoine ne peut pas être transféré : '.$disposed->pluck('number')->join(', ').'.',
            ]);
        }

        foreach ($assets as $asset) {
            $expected = $expectedVersions[$asset->getKey()] ?? null;

            // RG-86 : la fiche a changé depuis l'ouverture du formulaire.
            if ($expected !== null && $asset->lock_version !== $expected) {
                throw new ConcurrentModificationException(
                    $asset->updated_by ? User::query()->find($asset->updated_by) : null,
                    $asset->updated_at,
                );
            }
        }

        // RG-21 : au moins un des cinq éléments doit changer.
        $unchanged = $assets->filter(fn (Asset $asset): bool => $this->recorder->changedElements($this->recorder->assignmentOf($asset), $target) === []);

        if ($unchanged->isNotEmpty()) {
            throw ValidationException::withMessages([
                'assets' => $assets->count() === 1
                    ? 'La nouvelle affectation est identique à l\'affectation actuelle : modifiez au moins un élément.'
                    : 'Ces biens ont déjà cette affectation : '.$unchanged->pluck('number')->join(', ').'.',
            ]);
        }

        // Un référentiel désactivé ne reçoit pas de nouveau bien (sauf s'il y est déjà).
        $models = [
            'site_id' => [Site::class, 'name'], 'department_id' => [Department::class, 'name'], 'service_id' => [Service::class, 'name'],
            'room_id' => [Room::class, 'name'], 'holder_id' => [Holder::class, 'name'],
        ];

        foreach ($models as $element => [$class, $label]) {
            if ($target[$element] === null) {
                continue;
            }

            /** @var Model|null $model */
            $model = (new $class)->newQuery()->withoutGlobalScopes()->find($target[$element]);

            if ($model?->getAttribute('is_active') === false
                && $assets->contains(fn (Asset $asset): bool => $this->recorder->assignmentOf($asset)[$element] !== $target[$element])) {
                throw ValidationException::withMessages([$element => '« '.$model->getAttribute($label).' » est désactivé.']);
            }
        }
    }

    /**
     * RG-70 : chaque transfert est tracé, avec l'affectation avant et après.
     *
     * @param  array<string, int|null>  $from
     * @param  array<string, int|null>  $to
     */
    private function log(Asset $asset, Movement $movement, array $from, array $to): void
    {
        // Le journal garde les noms réels, quel que soit le périmètre de l'utilisateur : pas de scope global.
        $classes = [
            'site_id' => Site::class, 'department_id' => Department::class, 'service_id' => Service::class,
            'room_id' => Room::class, 'holder_id' => Holder::class,
        ];

        $names = function (array $assignment) use ($classes): array {
            $names = [];

            foreach ($classes as $element => $class) {
                $id = $assignment[$element] ?? null;
                /** @var Model|null $model */
                $model = $id === null ? null : (new $class)->newQuery()->withoutGlobalScopes()->find($id);
                $names[$element] = $model?->getAttribute('name');
            }

            return $names;
        };

        $changed = array_flip($this->recorder->changedElements($from, $to));
        $old = array_intersect_key($names($from), $changed);
        $new = array_intersect_key($names($to), $changed);

        activity('mouvements')
            ->performedOn($asset)
            ->event('transferred')
            ->withProperties([
                'old' => $old,
                'attributes' => $new + ['moved_on' => $movement->moved_on->format('d/m/Y'), 'reason' => $movement->reason],
                'batch_uuid' => $movement->batch_uuid,
            ])
            ->log('Transfert d\'un bien');
    }

    private function clean(?string $value): ?string
    {
        $value = $value === null ? null : trim($value);

        return $value === '' ? null : $value;
    }
}
