<?php

namespace App\Actions\Movements;

use App\Enums\MovementType;
use App\Models\Asset;
use App\Models\Movement;
use App\Services\MovementRecorder;

/**
 * RG-22 : l'affectation à la création d'un bien est son premier mouvement, de type « Affectation initiale ».
 * Sans effet si le bien en a déjà un (reprise du seeder de démonstration).
 */
class RecordInitialAssignment
{
    public function __construct(private MovementRecorder $recorder) {}

    public function __invoke(Asset $asset): Movement
    {
        $existing = Movement::query()->withoutGlobalScopes()
            ->where('asset_id', $asset->getKey())
            ->where('type', MovementType::Initial)
            ->first();

        return $existing ?? $this->recorder->record(
            $asset,
            null,
            $this->recorder->assignmentOf($asset),
            $asset->assigned_since ?? $asset->acquired_on,
        );
    }
}
