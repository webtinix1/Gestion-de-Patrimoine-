<?php

namespace App\Models;

use App\Enums\InventoryScopeType;
use App\Enums\InventoryStatus;
use App\Models\Concerns\RecordsAuthors;
use App\Models\Scopes\InventoryPerimeterScope;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * @property int $id
 * @property string $reference
 * @property InventoryScopeType $scope_type
 * @property int|null $scope_id
 * @property int|null $site_id
 * @property int|null $perimeter_site_id
 * @property int|null $perimeter_department_id
 * @property Carbon $starts_on
 * @property Carbon|null $ends_on
 * @property int $manager_id
 * @property InventoryStatus $status
 * @property string|null $notes
 * @property Carbon|null $started_at
 * @property int|null $started_by
 * @property Carbon|null $submitted_at
 * @property int|null $submitted_by
 * @property Carbon|null $closed_at
 * @property int|null $closed_by
 * @property string|null $closing_comment
 * @property int $lock_version
 * @property int|null $updated_by
 * @property Carbon|null $updated_at
 */
#[Fillable(['scope_type', 'scope_id', 'site_id', 'starts_on', 'ends_on', 'manager_id', 'notes'])]
class Inventory extends Model
{
    use RecordsAuthors;

    protected $attributes = ['status' => 'planned', 'lock_version' => 0];

    protected static function booted(): void
    {
        static::addGlobalScope(new InventoryPerimeterScope);

        static::updating(function (Inventory $inventory): void {
            // RG-48 : un inventaire clôturé est figé.
            if ($inventory->getOriginal('status') === InventoryStatus::Closed) {
                throw new LogicException('Un inventaire clôturé ne se modifie plus.');
            }

            // RG-86 : toute modification change la version.
            $inventory->setAttribute('lock_version', (int) $inventory->getOriginal('lock_version') + 1);
        });

        static::deleting(fn () => throw new LogicException('Un inventaire ne se supprime pas.'));
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'scope_type' => InventoryScopeType::class,
            'status' => InventoryStatus::class,
            'starts_on' => 'date',
            'ends_on' => 'date',
            'started_at' => 'datetime',
            'submitted_at' => 'datetime',
            'closed_at' => 'datetime',
            'lock_version' => 'integer',
        ];
    }

    public function isPlanned(): bool
    {
        return $this->status === InventoryStatus::Planned;
    }

    public function isInProgress(): bool
    {
        return $this->status === InventoryStatus::InProgress;
    }

    public function isToValidate(): bool
    {
        return $this->status === InventoryStatus::ToValidate;
    }

    public function isClosed(): bool
    {
        return $this->status === InventoryStatus::Closed;
    }

    /** RG-47, RG-82 : responsable ou participant désigné. */
    public function isDesignated(User $user): bool
    {
        return $this->manager_id === $user->getKey()
            || $this->participants()->where('user_id', $user->getKey())->exists();
    }

    /**
     * Libellé du périmètre, comme dans la maquette : « Annexe Talangaï · tout le site ».
     * Les noms sont lus sans scope : le libellé d'un inventaire visible reste complet.
     */
    public function scopeLabel(): string
    {
        $site = $this->site_id ? Site::query()->withoutGlobalScopes()->find($this->site_id)?->name : null;

        if ($this->scope_type === InventoryScopeType::Room) {
            $room = Room::query()->withoutGlobalScopes()->find($this->scope_id);

            return 'Local '.($room->name ?? '—').' · '.($room ? Site::query()->withoutGlobalScopes()->find($room->site_id)?->name : '—');
        }

        $value = match ($this->scope_type) {
            InventoryScopeType::Site => Site::query()->withoutGlobalScopes()->find($this->scope_id)?->name,
            InventoryScopeType::Department => Department::query()->withoutGlobalScopes()->find($this->scope_id)?->name,
            InventoryScopeType::Service => Service::query()->withoutGlobalScopes()->find($this->scope_id)?->name,
            InventoryScopeType::Category => AssetCategory::query()->withoutGlobalScopes()->find($this->scope_id)?->name,
            default => null,
        };

        return match ($this->scope_type) {
            InventoryScopeType::Site => ($value ?? '—').' · tout le site',
            InventoryScopeType::All => 'Tout le patrimoine · '.($site ?? 'tous sites'),
            default => $this->scope_type->valueLabel().' '.($value ?? '—').' · '.($site ?? 'tous sites'),
        };
    }

    public function periodLabel(): string
    {
        if ($this->ends_on === null || $this->ends_on->isSameDay($this->starts_on)) {
            return $this->starts_on->format('d/m/Y');
        }

        return $this->starts_on->format('d/m').' au '.$this->ends_on->format('d/m/Y');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function manager(): BelongsTo
    {
        return $this->belongsTo(User::class, 'manager_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function closer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'closed_by');
    }

    /**
     * @return BelongsTo<Site, $this>
     */
    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    /**
     * @return HasMany<InventoryParticipant, $this>
     */
    public function participants(): HasMany
    {
        return $this->hasMany(InventoryParticipant::class)->orderBy('id');
    }

    /**
     * @return HasMany<InventoryLine, $this>
     */
    public function lines(): HasMany
    {
        return $this->hasMany(InventoryLine::class);
    }
}
