<?php

namespace App\Actions\Disposals;

use App\Enums\DisposalAccountingStatus;
use App\Enums\PermissionAbility;
use App\Enums\PermissionModule;
use App\Models\Asset;
use App\Models\Disposal;
use App\Models\User;
use App\Support\DepreciationAuthority;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * E27 : validation comptable d'une sortie (RG-54), une seule fois, tracée (RG-70).
 * Ne touche ni au bien, ni au plan, ni aux valeurs figées : aucune écriture comptable (Q8).
 */
class ValidateDisposal
{
    public function __invoke(Disposal $disposal, User $user): Disposal
    {
        // RG-83 : réservé à l'administrateur et au comptable, en dur, en plus de la matrice des droits.
        if (! self::allows($user)) {
            throw new AuthorizationException('Seuls l\'administrateur et le comptable valident les sorties du patrimoine (RG-83).');
        }

        return DB::transaction(function () use ($disposal, $user): Disposal {
            // RG-81 : le scope de la sortie (périmètre du bien) s'applique.
            $current = Disposal::query()->lockForUpdate()->find($disposal->getKey());

            if (! $current instanceof Disposal) {
                throw ValidationException::withMessages(['disposal' => 'Cette sortie est introuvable ou hors de votre périmètre.']);
            }

            if ($current->isValidated()) {
                throw ValidationException::withMessages(['disposal' => 'Cette sortie est déjà validée.']);
            }

            $current->forceFill([
                'accounting_status' => DisposalAccountingStatus::Validated,
                'accounting_validated_by' => $user->getKey(),
                'accounting_validated_at' => now(),
            ])->save();

            activity('sorties')
                ->performedOn(Asset::query()->withoutGlobalScopes()->findOrFail($current->asset_id))
                ->causedBy($user)
                ->event('disposal_validated')
                ->withProperties([
                    'old' => ['accounting_status' => DisposalAccountingStatus::Pending->getLabel()],
                    'attributes' => ['accounting_status' => DisposalAccountingStatus::Validated->getLabel(), 'reference' => $current->reference],
                ])
                ->log('Validation comptable d\'une sortie');

            $disposal->setRawAttributes($current->getAttributes(), sync: true);

            return $disposal;
        }, attempts: 3);
    }

    public static function allows(?User $user): bool
    {
        return DepreciationAuthority::isAccountingRole($user)
            && $user instanceof User
            && $user->can(PermissionModule::Disposals->permission(PermissionAbility::Validate));
    }
}
