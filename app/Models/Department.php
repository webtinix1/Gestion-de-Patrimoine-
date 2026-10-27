<?php

namespace App\Models;

use App\Models\Concerns\HasPerimeter;
use App\Models\Concerns\IsReferential;
use App\Models\Concerns\RecordsAuthors;
use Database\Factories\DepartmentFactory;
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
 * @property int|null $manager_id
 * @property bool $is_active
 */
#[Fillable(['name', 'code', 'manager_id', 'is_active'])]
class Department extends Model
{
    /** @use HasFactory<DepartmentFactory> */
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
     * @return BelongsToMany<Site, $this>
     */
    public function sites(): BelongsToMany
    {
        return $this->belongsToMany(Site::class);
    }

    /**
     * @return HasMany<Service, $this>
     */
    public function services(): HasMany
    {
        return $this->hasMany(Service::class);
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
        return ['services', 'rooms'];
    }

    public function perimeterColumns(): array
    {
        return ['department' => 'id'];
    }
}
