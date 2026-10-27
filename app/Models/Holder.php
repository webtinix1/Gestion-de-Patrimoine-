<?php

namespace App\Models;

use App\Models\Concerns\RecordsAuthors;
use Database\Factories\HolderFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
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
     * @return HasMany<Asset, $this>
     */
    public function assets(): HasMany
    {
        return $this->hasMany(Asset::class);
    }
}
