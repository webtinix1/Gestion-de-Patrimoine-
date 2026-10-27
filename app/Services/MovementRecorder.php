<?php

namespace App\Services;

use App\Enums\MovementType;
use App\Models\Asset;
use App\Models\Movement;
use Carbon\CarbonInterface;

/**
 * RG-21 : écrit un mouvement qui conserve l'affectation précédente et la nouvelle.
 */
class MovementRecorder
{
    /**
     * Les cinq éléments de l'affectation (RG-20) et le type de mouvement qu'ils produisent seuls.
     *
     * @var array<string, MovementType>
     */
    public const array ELEMENTS = [
        'site_id' => MovementType::Site,
        'department_id' => MovementType::Department,
        'service_id' => MovementType::Service,
        'room_id' => MovementType::Room,
        'holder_id' => MovementType::Holder,
    ];

    /**
     * @return array<string, int|null>
     */
    public function assignmentOf(Asset $asset): array
    {
        $assignment = [];

        foreach (array_keys(self::ELEMENTS) as $element) {
            $value = $asset->getAttribute($element);
            $assignment[$element] = $value === null ? null : (int) $value;
        }

        return $assignment;
    }

    /**
     * @param  array<string, int|null>  $from
     * @param  array<string, int|null>  $to
     * @return list<string>
     */
    public function changedElements(array $from, array $to): array
    {
        return array_values(array_filter(
            array_keys(self::ELEMENTS),
            fn (string $element): bool => ($from[$element] ?? null) !== ($to[$element] ?? null),
        ));
    }

    /**
     * @param  list<string>  $changed
     */
    public function typeFor(array $changed): MovementType
    {
        return count($changed) === 1 ? self::ELEMENTS[$changed[0]] : MovementType::Mixed;
    }

    /**
     * @param  array<string, int|null>|null  $from  null pour l'affectation initiale (RG-22)
     * @param  array<string, int|null>  $to
     */
    public function record(
        Asset $asset,
        ?array $from,
        array $to,
        CarbonInterface|string $movedOn,
        ?string $reason = null,
        ?string $notes = null,
        ?string $batchUuid = null,
    ): Movement {
        $attributes = [
            'asset_id' => $asset->getKey(),
            'type' => $from === null ? MovementType::Initial : $this->typeFor($this->changedElements($from, $to)),
            'moved_on' => $movedOn,
            'reason' => $reason,
            'notes' => $notes,
            'batch_uuid' => $batchUuid,
            'created_by' => auth()->id(),
        ];

        foreach (array_keys(self::ELEMENTS) as $element) {
            $attributes['from_'.$element] = $from[$element] ?? null;
            $attributes['to_'.$element] = $to[$element] ?? null;
        }

        return Movement::query()->create($attributes);
    }
}
