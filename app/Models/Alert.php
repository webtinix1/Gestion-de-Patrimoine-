<?php

namespace App\Models;

use App\Enums\AlertType;
use App\Enums\InventoryLineResult;
use App\Enums\PermissionAbility;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Carbon;

/**
 * RG-90, RG-91 : alerte interne. Elle ne copie aucune donnée de l'objet concerné : le périmètre de
 * l'utilisateur s'applique à l'objet (scopes globaux de Loan, MaintenanceJob, Asset, Inventory…).
 *
 * @property int $id
 * @property AlertType $type
 * @property string $fingerprint
 * @property string $subject_type
 * @property int $subject_id
 * @property Carbon|null $event_on
 * @property Carbon $raised_at
 * @property Carbon|null $resolved_at
 * @property Model|null $subject
 */
#[Fillable(['type', 'fingerprint', 'subject_type', 'subject_id', 'event_on', 'raised_at', 'resolved_at'])]
class Alert extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => AlertType::class,
            'event_on' => 'date',
            'raised_at' => 'datetime',
            'resolved_at' => 'datetime',
        ];
    }

    /**
     * @return MorphTo<Model, $this>
     */
    public function subject(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * @return BelongsToMany<User, $this>
     */
    public function readers(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'alert_reads')->withPivot('read_at');
    }

    /**
     * @param  Builder<Alert>  $query
     */
    public function scopeActive(Builder $query): void
    {
        $query->whereNull($this->qualifyColumn('resolved_at'));
    }

    /**
     * Alertes que l'utilisateur a le droit de voir : droit « consulter » du module, et objet concerné visible
     * dans son périmètre (RG-81). Un utilisateur sans périmètre restreint voit tout ce que son rôle permet.
     *
     * @param  Builder<Alert>  $query
     */
    public function scopeVisibleTo(Builder $query, User $user): void
    {
        $types = array_values(array_filter(
            AlertType::cases(),
            fn (AlertType $type): bool => $user->is_active && $user->can($type->module()->permission(PermissionAbility::View)),
        ));

        $query->whereIn($this->qualifyColumn('type'), array_map(fn (AlertType $type): string => $type->value, $types));

        if ($user->perimeterSiteIds() === null && $user->perimeterDepartmentIds() === null) {
            return;
        }

        $query->whereHasMorph('subject', [Asset::class, Loan::class, MaintenanceJob::class, Inventory::class])
            // Écarts d'inventaire : visibles seulement s'il reste des écarts non régularisés dans le périmètre.
            ->where(function (Builder $query): void {
                $query->where($this->qualifyColumn('type'), '!=', AlertType::InventoryGaps->value)
                    ->orWhereHasMorph('subject', [Inventory::class], fn (Builder $inventory) => $inventory->whereHas(
                        'lines',
                        fn (Builder $lines) => $lines->whereIn('result', InventoryLineResult::discrepancyValues())->whereNull('resolution'),
                    ));
            });
    }

    /**
     * @param  Builder<Alert>  $query
     */
    public function scopeUnreadBy(Builder $query, User $user): void
    {
        $query->whereDoesntHave('readers', fn (Builder $readers) => $readers->whereKey($user->getKey()));
    }
}
