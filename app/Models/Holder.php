<?php

namespace App\Models;

use App\Models\Concerns\RecordsAuthors;
use Database\Factories\HolderFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Q11 : responsable ou affectataire d'un bien, lié ou non à un compte utilisateur.
 *
 * @property int $id
 * @property string $name
 * @property string|null $phone
 * @property int|null $department_id
 * @property int|null $user_id
 * @property bool $is_active
 */
#[Fillable(['name', 'phone', 'department_id', 'user_id', 'is_active'])]
class Holder extends Model
{
    /** @use HasFactory<HolderFactory> */
    use HasFactory, RecordsAuthors;

    protected $attributes = ['is_active' => true];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    /**
     * @return BelongsTo<Department, $this>
     */
    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * RG-81 : un utilisateur au périmètre limité ne voit que les responsables de ses départements ou qui portent
     * un bien de son périmètre (le scope du bien s'applique).
     *
     * @param  Builder<Holder>  $query
     */
    public function scopeInPerimeter(Builder $query): void
    {
        $user = auth()->user();

        if (! $user instanceof User || ($user->perimeterSiteIds() === null && $user->perimeterDepartmentIds() === null)) {
            return;
        }

        $departments = $user->perimeterDepartmentIds();

        $query->where(function (Builder $any) use ($departments): void {
            if ($departments !== null) {
                $any->whereIn('holders.department_id', $departments);
            }

            $any->orWhereIn('holders.id', Asset::query()->whereNotNull('holder_id')->select('holder_id'));
        });
    }

    /**
     * @return HasMany<Asset, $this>
     */
    public function assets(): HasMany
    {
        return $this->hasMany(Asset::class);
    }
}
