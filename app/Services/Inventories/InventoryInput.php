<?php

namespace App\Services\Inventories;

use App\Enums\PermissionAbility;
use App\Enums\PermissionModule;
use App\Models\Room;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

/**
 * Contrôle la saisie d'un inventaire (E19) : périmètre (RG-44), dates, responsable et participants.
 * Les erreurs portent la clé du champ du formulaire (data.*).
 */
class InventoryInput
{
    public function __construct(private InventoryScope $scope) {}

    /**
     * @param  array<string, mixed>  $data
     * @return array{header: array<string, mixed>, participants: list<array{user_id: int, room_id: int|null}>}
     */
    public function normalize(array $data, ?User $user): array
    {
        $scope = $this->scope->normalize($data, $user);
        $errors = [];

        $startsOn = filled($data['starts_on'] ?? null) ? Carbon::parse($data['starts_on'])->startOfDay() : null;
        $endsOn = filled($data['ends_on'] ?? null) ? Carbon::parse($data['ends_on'])->startOfDay() : null;

        if ($startsOn === null) {
            $errors['data.starts_on'] = 'La date de début est obligatoire.';
        }

        if ($startsOn !== null && $endsOn !== null && $endsOn->lt($startsOn)) {
            $errors['data.ends_on'] = 'La date de fin ne peut pas précéder la date de début.';
        }

        $managerId = filled($data['manager_id'] ?? null) ? (int) $data['manager_id'] : null;

        if ($managerId === null) {
            $errors['data.manager_id'] = 'Le responsable de l\'inventaire est obligatoire.';
        } elseif (! $this->canTakePart($managerId)) {
            $errors['data.manager_id'] = 'Ce responsable n\'est pas un compte actif autorisé à saisir les inventaires.';
        }

        $participants = [];

        foreach (array_values((array) ($data['participants'] ?? [])) as $index => $row) {
            $row = (array) $row;
            $participantId = filled($row['user_id'] ?? null) ? (int) $row['user_id'] : null;

            if ($participantId === null) {
                continue;
            }

            if (! $this->canTakePart($participantId)) {
                $errors["data.participants.{$index}.user_id"] = 'Ce participant n\'est pas un compte actif autorisé à saisir les inventaires.';

                continue;
            }

            if (isset($participants[$participantId])) {
                $errors["data.participants.{$index}.user_id"] = 'Ce participant figure déjà dans la liste.';

                continue;
            }

            $roomId = filled($row['room_id'] ?? null) ? (int) $row['room_id'] : null;

            if ($roomId !== null && ! Room::query()->withoutGlobalScopes()->whereKey($roomId)->exists()) {
                $errors["data.participants.{$index}.room_id"] = 'Ce local n\'existe pas.';
            }

            $participants[$participantId] = ['user_id' => $participantId, 'room_id' => $roomId];
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        $notes = trim((string) ($data['notes'] ?? ''));

        return [
            'header' => [
                ...$scope,
                'starts_on' => $startsOn?->toDateString(),
                'ends_on' => $endsOn?->toDateString(),
                'manager_id' => $managerId,
                'notes' => $notes === '' ? null : $notes,
            ],
            'participants' => array_values($participants),
        ];
    }

    /**
     * Les personnes désignées doivent pouvoir saisir (droit « modifier » des inventaires, RG-82).
     */
    public function canTakePart(int $userId): bool
    {
        $user = User::query()->find($userId);

        return $user instanceof User && $user->is_active
            && $user->can(PermissionModule::Inventories->permission(PermissionAbility::Update));
    }
}
