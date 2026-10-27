<?php

namespace App\Models;

use App\Enums\RoomType;
use App\Models\Concerns\HasPerimeter;
use App\Models\Concerns\IsReferential;
use App\Models\Concerns\RecordsAuthors;
use Database\Factories\RoomFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $site_id
 * @property int|null $department_id
 * @property string $name
 * @property string $code
 * @property RoomType $type
 * @property bool $is_active
 */
#[Fillable(['site_id', 'department_id', 'name', 'code', 'type', 'is_active'])]
class Room extends Model
{
    /** @use HasFactory<RoomFactory> */
    use HasFactory, HasPerimeter, IsReferential, RecordsAuthors;

    protected $attributes = ['is_active' => true, 'type' => 'other'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => RoomType::class,
            'is_active' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<Site, $this>
     */
    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    /**
     * @return BelongsTo<Department, $this>
     */
    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    public function usageRelations(): array
    {
        return [];
    }

    public function perimeterColumns(): array
    {
        return ['site' => 'site_id', 'department' => 'department_id', 'department_nullable' => true];
    }
}
