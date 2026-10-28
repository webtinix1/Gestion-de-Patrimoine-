<?php

namespace App\Services\Inventories;

use App\Enums\AssetStatus;
use App\Enums\InventoryScopeType;
use App\Models\Asset;
use App\Models\AssetCategory;
use App\Models\Department;
use App\Models\Inventory;
use App\Models\Room;
use App\Models\Service;
use App\Models\Site;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\ValidationException;

/**
 * RG-44 : périmètre d'un inventaire (site, département, service, catégorie, local ou tout le patrimoine,
 * éventuellement croisé avec un site) et biens attendus.
 *
 * RG-81 : un utilisateur au périmètre limité ne crée et ne démarre qu'un inventaire contenu dans son
 * périmètre ; la liste figée est alors la même quel que soit l'utilisateur qui démarre.
 */
class InventoryScope
{
    /**
     * @param  array<string, mixed>  $data
     * @return array{scope_type: InventoryScopeType, scope_id: int|null, site_id: int|null, perimeter_site_id: int|null, perimeter_department_id: int|null}
     */
    public function normalize(array $data, ?User $user): array
    {
        $type = $data['scope_type'] ?? null;
        $type = $type instanceof InventoryScopeType ? $type : InventoryScopeType::tryFrom((string) $type);

        if ($type === null) {
            throw ValidationException::withMessages(['data.scope_type' => 'Choisissez le type de périmètre.']);
        }

        $scopeId = $type->needsValue() && filled($data['scope_id'] ?? null) ? (int) $data['scope_id'] : null;
        $siteId = $type->allowsSiteFilter() && filled($data['site_id'] ?? null) ? (int) $data['site_id'] : null;

        if ($type->needsValue() && $scopeId === null) {
            throw ValidationException::withMessages(['data.scope_id' => 'Choisissez le '.mb_strtolower($type->valueLabel()).' inventorié.']);
        }

        $target = $this->scopeModel($type, $scopeId);

        if ($type->needsValue() && $target === null) {
            throw ValidationException::withMessages(['data.scope_id' => 'Cet élément n\'existe pas.']);
        }

        if ($siteId !== null && ! Site::query()->withoutGlobalScopes()->whereKey($siteId)->exists()) {
            throw ValidationException::withMessages(['data.site_id' => 'Ce site n\'existe pas.']);
        }

        $perimeterSite = match ($type) {
            InventoryScopeType::Site => $scopeId,
            InventoryScopeType::Room => $target instanceof Room ? $target->site_id : null,
            default => $siteId,
        };

        $perimeterDepartment = match ($type) {
            InventoryScopeType::Department => $scopeId,
            InventoryScopeType::Service => $target instanceof Service ? $target->department_id : null,
            default => null,
        };

        $normalized = [
            'scope_type' => $type,
            'scope_id' => $scopeId,
            'site_id' => $siteId,
            'perimeter_site_id' => $perimeterSite,
            'perimeter_department_id' => $perimeterDepartment,
        ];

        if ($user !== null) {
            $this->assertContainedIn($normalized['perimeter_site_id'], $normalized['perimeter_department_id'], $user);
        }

        return $normalized;
    }

    /**
     * RG-81 : le périmètre de l'inventaire doit être contenu dans celui de l'utilisateur.
     */
    public function assertContainedIn(?int $siteId, ?int $departmentId, User $user): void
    {
        $sites = $user->perimeterSiteIds();
        $departments = $user->perimeterDepartmentIds();

        if ($sites !== null && ! in_array($siteId, $sites, true)) {
            throw ValidationException::withMessages([
                'data.scope_id' => 'Ce périmètre dépasse le vôtre : choisissez un site de votre périmètre (ou limitez l\'inventaire à l\'un de vos sites).',
            ]);
        }

        if ($departments !== null && ! in_array($departmentId, $departments, true)) {
            throw ValidationException::withMessages([
                'data.scope_id' => 'Ce périmètre dépasse le vôtre : choisissez un département ou un service de votre périmètre.',
            ]);
        }
    }

    public function isContainedIn(Inventory $inventory, User $user): bool
    {
        try {
            $this->assertContainedIn($inventory->perimeter_site_id, $inventory->perimeter_department_id, $user);
        } catch (ValidationException) {
            return false;
        }

        return true;
    }

    /**
     * Biens actifs du périmètre, sans le scope de l'utilisateur : la liste ne dépend que de l'inventaire.
     *
     * @return Builder<Asset>
     */
    public function assets(InventoryScopeType $type, ?int $scopeId, ?int $siteId): Builder
    {
        $active = array_values(array_filter(AssetStatus::cases(), fn (AssetStatus $status): bool => $status->isInActivePatrimony()));

        $query = Asset::query()->withoutGlobalScopes()
            ->whereNull('disposed_at')
            ->whereIn('status', array_map(fn (AssetStatus $status): string => $status->value, $active));

        $column = $type->assetColumn();

        if ($column !== null) {
            $query->where($column, $scopeId);
        }

        if ($siteId !== null && $type->allowsSiteFilter()) {
            $query->where('site_id', $siteId);
        }

        return $query;
    }

    /**
     * @return Builder<Asset>
     */
    public function assetsOf(Inventory $inventory): Builder
    {
        return $this->assets($inventory->scope_type, $inventory->scope_id, $inventory->site_id);
    }

    private function scopeModel(InventoryScopeType $type, ?int $id): Site|Department|Service|AssetCategory|Room|null
    {
        if ($id === null) {
            return null;
        }

        return match ($type) {
            InventoryScopeType::Site => Site::query()->withoutGlobalScopes()->find($id),
            InventoryScopeType::Department => Department::query()->withoutGlobalScopes()->find($id),
            InventoryScopeType::Service => Service::query()->withoutGlobalScopes()->find($id),
            InventoryScopeType::Category => AssetCategory::query()->find($id),
            InventoryScopeType::Room => Room::query()->withoutGlobalScopes()->find($id),
            InventoryScopeType::All => null,
        };
    }
}
