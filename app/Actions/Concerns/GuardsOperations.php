<?php

namespace App\Actions\Concerns;

use App\Enums\PermissionAbility;
use App\Enums\PermissionModule;
use App\Exceptions\ConcurrentModificationException;
use App\Models\Asset;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * Contrôles communs aux opérations qui touchent un bien (prêt, maintenance, signalement).
 */
trait GuardsOperations
{
    protected function authorizeModule(PermissionModule $module, PermissionAbility $ability, string $message): void
    {
        $user = auth()->user();

        if ($user instanceof User && ! ($user->is_active && $user->can($module->permission($ability)))) {
            throw new AuthorizationException($message);
        }
    }

    /**
     * Verrouille les biens dans l'ordre des identifiants (jamais d'interblocage entre deux opérations).
     * RG-81 : le scope du bien s'applique, un bien hors périmètre n'est pas trouvé.
     *
     * @param  iterable<int>  $ids
     * @return Collection<int, Asset>
     */
    protected function lockAssets(iterable $ids, string $field = 'assets'): Collection
    {
        $ids = collect($ids)->map(fn (mixed $id): int => (int) $id)->unique()->sort()->values();

        $assets = Asset::query()->whereKey($ids->all())->orderBy('id')->lockForUpdate()->get()->keyBy('id');

        if ($assets->count() !== $ids->count()) {
            throw ValidationException::withMessages([$field => 'Certains biens sont introuvables ou hors de votre périmètre.']);
        }

        return $assets;
    }

    /**
     * RG-86 : la fiche a changé depuis l'ouverture du formulaire.
     */
    protected function assertVersion(Asset $asset, ?int $expected): void
    {
        if ($expected !== null && $asset->lock_version !== $expected) {
            throw new ConcurrentModificationException(
                $asset->updated_by ? User::query()->find($asset->updated_by) : null,
                $asset->updated_at,
            );
        }
    }

    protected function date(mixed $value, string $field, string $required, bool $allowFuture = false): Carbon
    {
        if (blank($value)) {
            throw ValidationException::withMessages([$field => $required]);
        }

        $date = Carbon::parse($value)->startOfDay();

        if (! $allowFuture && $date->isAfter(today())) {
            throw ValidationException::withMessages([$field => 'Cette date ne peut pas être dans le futur.']);
        }

        return $date;
    }

    protected function clean(mixed $value): ?string
    {
        $value = is_string($value) ? trim($value) : null;

        return $value === '' ? null : $value;
    }

    protected function money(mixed $value, string $field): ?int
    {
        if (blank($value)) {
            return null;
        }

        if (! is_numeric($value) || (float) $value < 0 || (float) $value !== (float) (int) $value) {
            throw ValidationException::withMessages([$field => 'Le montant doit être un nombre entier de FCFA, positif ou nul.']);
        }

        return (int) $value;
    }
}
