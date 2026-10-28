<?php

namespace App\Actions\Inventories;

use App\Actions\Assets\CreateAsset;
use App\Actions\Assets\UpdateAsset;
use App\Actions\Movements\TransferAssets;
use App\Enums\InventoryResolution;
use App\Enums\PermissionAbility;
use App\Enums\PermissionModule;
use App\Models\Asset;
use App\Models\Department;
use App\Models\Inventory;
use App\Models\InventoryLine;
use App\Models\Site;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Validation\ValidationException;

/**
 * RG-48 : régularisations depuis le rapport d'un inventaire clôturé, chacune par l'opération du module
 * concerné (avec ses propres règles et droits), puis liée à la ligne par ResolveInventoryLine, dans la
 * même transaction (la plus externe, rejouée en cas d'interblocage). Ordre des verrous : le bien
 * (par l'opération), puis la ligne.
 */
class RegularizeInventoryLine
{
    public const string TRANSFER_REASON = 'Régularisation d\'inventaire';

    public function __construct(private ResolveInventoryLine $resolve) {}

    /**
     * Autre affectation ou présent hors périmètre : transfert vers l'affectation constatée (RG-21).
     *
     * @param  array<string, mixed>  $data  données du transfert (TransferAssets)
     */
    public function transfer(InventoryLine $line, User $user, array $data, int $expectedVersion): InventoryLine
    {
        [$line, $inventory, $asset] = $this->prepare($line, $user, InventoryResolution::Transfer);
        $asset = $this->required($asset);

        $data['reason'] = filled($data['reason'] ?? null) ? $data['reason'] : self::TRANSFER_REASON;
        $data['notes'] = trim(($data['notes'] ?? '').' Inventaire '.$inventory->reference.'.');

        return DB::transaction(function () use ($line, $asset, $data, $expectedVersion, $user): InventoryLine {
            $movement = app(TransferAssets::class)([$asset], $data, [$asset->id => $expectedVersion])->sole();

            return ($this->resolve)($line, InventoryResolution::Transfer, $movement, null, $user);
        }, attempts: 5);
    }

    /**
     * État différent : la fiche prend l'état constaté (UpdateAsset, RG-86).
     */
    public function updateCondition(InventoryLine $line, User $user, int $expectedVersion): InventoryLine
    {
        [$line, , $asset] = $this->prepare($line, $user, InventoryResolution::ConditionUpdate);
        $asset = $this->required($asset);

        if (! $user->can('update', $asset)) {
            throw new AuthorizationException('Vous n\'avez pas le droit de modifier cette fiche.');
        }

        if ($line->found_condition_id === null) {
            throw ValidationException::withMessages(['resolution' => 'Aucun état constaté sur cette ligne.']);
        }

        return DB::transaction(function () use ($line, $asset, $expectedVersion, $user): InventoryLine {
            $data = $asset->only($asset->getFillable());
            $data['asset_condition_id'] = $line->found_condition_id;

            app(UpdateAsset::class)($asset, $data, $expectedVersion);

            return ($this->resolve)($line, InventoryResolution::ConditionUpdate, $asset, null, $user);
        }, attempts: 5);
    }

    /**
     * Bien trouvé sans fiche : création de la fiche (CreateAsset, numéro attribué sous verrou).
     *
     * @param  array<string, mixed>  $data  données de la fiche
     */
    public function createAsset(InventoryLine $line, User $user, array $data): InventoryLine
    {
        [$line] = $this->prepare($line, $user, InventoryResolution::CreatedAsset);

        if (! $user->can('create', Asset::class)) {
            throw new AuthorizationException('Vous n\'avez pas le droit de créer une fiche.');
        }

        // RG-81 : la nouvelle fiche reste dans le périmètre de l'utilisateur.
        foreach (['site_id' => 'site', 'department_id' => 'département'] as $field => $label) {
            $model = $field === 'site_id' ? Site::class : Department::class;

            if (! filled($data[$field] ?? null) || ! $model::query()->whereKey($data[$field])->exists()) {
                throw ValidationException::withMessages(['data.'.$field => 'Choisissez un '.$label.' de votre périmètre.']);
            }
        }

        return DB::transaction(function () use ($line, $data, $user): InventoryLine {
            $asset = app(CreateAsset::class)($data);

            return ($this->resolve)($line, InventoryResolution::CreatedAsset, $asset, null, $user);
        }, attempts: 5);
    }

    /**
     * Bien manquant retrouvé après la clôture : la ligne garde « manquant » (figée), la régularisation le note.
     */
    public function markFound(InventoryLine $line, User $user, ?string $notes): InventoryLine
    {
        [$line] = $this->prepare($line, $user, InventoryResolution::Found);

        return DB::transaction(fn (): InventoryLine => ($this->resolve)($line, InventoryResolution::Found, null, $notes, $user), attempts: 5);
    }

    /**
     * Contrat avec le lot 10 : lien vers la sortie pour perte, seulement si la page existe.
     */
    public static function disposalUrl(InventoryLine $line): ?string
    {
        foreach (Route::getRoutes()->getRoutes() as $route) {
            if ($route->uri() === 'admin/sorties/create') {
                return url('/admin/sorties/create').'?'.http_build_query([
                    'bien' => $line->asset_id,
                    'motif' => 'loss',
                    'inventaire_ligne' => $line->id,
                ]);
            }
        }

        return null;
    }

    /**
     * @return array{0: InventoryLine, 1: Inventory, 2: Asset|null}
     */
    private function prepare(InventoryLine $line, User $user, InventoryResolution $type): array
    {
        // RG-81 : la ligne et son bien doivent être dans le périmètre de l'utilisateur.
        $current = InventoryLine::query()->find($line->getKey());
        $inventory = $current ? Inventory::query()->find($current->inventory_id) : null;

        if (! $current instanceof InventoryLine || ! $inventory instanceof Inventory) {
            throw ValidationException::withMessages(['resolution' => 'Cette ligne est introuvable dans votre périmètre.']);
        }

        if (! $user->can('regularize', $inventory) || ($type === InventoryResolution::Transfer
            && ! $user->can(PermissionModule::Movements->permission(PermissionAbility::Create)))) {
            throw new AuthorizationException('Vous n\'avez pas le droit de faire cette régularisation.');
        }

        if ($current->resolution !== null) {
            throw ValidationException::withMessages(['resolution' => 'Cette ligne est déjà régularisée ('.$current->resolution->getLabel().').']);
        }

        if (! in_array($current->result, $type->appliesTo(), true)) {
            throw ValidationException::withMessages(['resolution' => '« '.$type->getLabel().' » ne s\'applique pas à un résultat « '.$current->result->getLabel().' ».']);
        }

        $asset = $current->asset_id !== null ? Asset::query()->find($current->asset_id) : null;

        if ($type !== InventoryResolution::CreatedAsset && ! $asset instanceof Asset) {
            throw ValidationException::withMessages(['resolution' => 'Le bien de cette ligne est introuvable dans votre périmètre.']);
        }

        return [$current, $inventory, $asset];
    }

    private function required(?Asset $asset): Asset
    {
        if (! $asset instanceof Asset) {
            throw ValidationException::withMessages(['resolution' => 'Le bien de cette ligne est introuvable dans votre périmètre.']);
        }

        return $asset;
    }
}
