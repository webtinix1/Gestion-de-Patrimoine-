<?php

namespace App\Models;

use App\Models\Concerns\HasPerimeter;
use App\Models\Concerns\IsReferential;
use App\Models\Concerns\RecordsAuthors;
use Database\Factories\SiteFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property string $name
 * @property string $code
 * @property string|null $address
 * @property int|null $manager_id
 * @property string|null $description
 * @property bool $is_active
 */
#[Fillable(['name', 'code', 'address', 'manager_id', 'description', 'is_active'])]
class Site extends Model
{
    /** @use HasFactory<SiteFactory> */
    use HasFactory, HasPerimeter, IsReferential, RecordsAuthors;

    protected $attributes = ['is_active' => true];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function manager(): BelongsTo
    {
        return $this->belongsTo(User::class, 'manager_id');
    }

    /**
     * @return BelongsToMany<Department, $this>
     */
    public function departments(): BelongsToMany
    {
        return $this->belongsToMany(Department::class);
    }

    /**
     * @return HasMany<Room, $this>
     */
    public function rooms(): HasMany
    {
        return $this->hasMany(Room::class);
    }

    public function usageRelations(): array
    {
        return ['rooms', 'departments'];
    }

    public function perimeterColumns(): array
    {
        return ['site' => 'id'];
    }
}
