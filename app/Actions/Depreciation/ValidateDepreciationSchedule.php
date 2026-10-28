<?php

namespace App\Actions\Depreciation;

use App\Enums\PermissionAbility;
use App\Models\Asset;
use App\Models\DepreciationSchedule;
use App\Models\User;
use App\Support\DepreciationAuthority;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * RG-38 : le comptable valide le plan courant ; la version validée est conservée telle quelle,
 * tout recalcul ultérieur crée une nouvelle version.
 */
class ValidateDepreciationSchedule
{
    public function __invoke(DepreciationSchedule $schedule, User $user): DepreciationSchedule
    {
        // RG-83 : règle appliquée aussi dans l'action, en plus de la policy de l'écran.
        if (! DepreciationAuthority::canManage($user, PermissionAbility::Validate)) {
            throw new AuthorizationException('Seuls l\'administrateur et le comptable valident les plans d\'amortissement (RG-83).');
        }

        return DB::transaction(function () use ($schedule, $user): DepreciationSchedule {
            $current = DepreciationSchedule::query()->lockForUpdate()->findOrFail($schedule->getKey());

            if (! $current->is_current) {
                throw ValidationException::withMessages(['schedule' => 'Seul le plan courant peut être validé.']);
            }

            if ($current->isValidated()) {
                throw ValidationException::withMessages(['schedule' => 'Cette version du plan est déjà validée.']);
            }

            $current->forceFill(['validated_by' => $user->getKey(), 'validated_at' => now()])->save();

            // RG-70 : validation d'un plan.
            activity('amortissements')
                ->performedOn(Asset::query()->withoutGlobalScopes()->findOrFail($current->asset_id))
                ->causedBy($user)
                ->event('depreciation_schedule_validated')
                ->withProperties(['attributes' => ['version' => $current->version, 'base' => $current->base_amount, 'duration_years' => $current->duration_years]])
                ->log('Validation du plan d\'amortissement');

            $schedule->setRawAttributes($current->getAttributes(), sync: true);

            return $schedule;
        });
    }
}
