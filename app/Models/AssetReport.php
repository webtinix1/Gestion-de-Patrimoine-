<?php

namespace App\Models;

use App\Enums\ReportStatus;
use App\Enums\ReportType;
use App\Enums\ReportUrgency;
use App\Models\Concerns\RecordsAuthors;
use App\Models\Scopes\MovementPerimeterScope;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * @property int $id
 * @property string $reference
 * @property int $asset_id
 * @property ReportType $type
 * @property int|null $reported_condition_id
 * @property int|null $reported_room_id
 * @property int|null $reported_department_id
 * @property ReportUrgency $urgency
 * @property string $description
 * @property string|null $photo_path
 * @property ReportStatus $status
 * @property string|null $rejection_reason
 * @property int|null $handled_by
 * @property Carbon|null $handled_at
 * @property string|null $handled_ref_type
 * @property int|null $handled_ref_id
 * @property int|null $created_by
 * @property Carbon|null $created_at
 */
#[Fillable([
    'reference', 'asset_id', 'type', 'reported_condition_id', 'reported_room_id', 'reported_department_id',
    'urgency', 'description', 'photo_path', 'status', 'rejection_reason', 'handled_by', 'handled_at',
])]
class AssetReport extends Model
{
    use RecordsAuthors;

    protected static function booted(): void
    {
        // RG-81 : un signalement est visible si son bien l'est.
        static::addGlobalScope(new MovementPerimeterScope);

        static::updating(function (AssetReport $report): void {
            if (! $report->getOriginal('status')->isOpen()) {
                throw new LogicException('Un signalement traité ou rejeté n\'est plus modifiable.');
            }
        });
        static::deleting(fn () => throw new LogicException('Un signalement ne se supprime pas.'));
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => ReportType::class,
            'urgency' => ReportUrgency::class,
            'status' => ReportStatus::class,
            'handled_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Asset, $this>
     */
    public function asset(): BelongsTo
    {
        return $this->belongsTo(Asset::class);
    }

    /**
     * @return BelongsTo<AssetCondition, $this>
     */
    public function reportedCondition(): BelongsTo
    {
        return $this->belongsTo(AssetCondition::class, 'reported_condition_id');
    }

    /**
     * @return BelongsTo<Room, $this>
     */
    public function reportedRoom(): BelongsTo
    {
        return $this->belongsTo(Room::class, 'reported_room_id')->withoutGlobalScopes();
    }

    /**
     * @return BelongsTo<Department, $this>
     */
    public function reportedDepartment(): BelongsTo
    {
        return $this->belongsTo(Department::class, 'reported_department_id')->withoutGlobalScopes();
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function handler(): BelongsTo
    {
        return $this->belongsTo(User::class, 'handled_by');
    }

    /**
     * Opération créée pour traiter le signalement (intervention, mouvement ou bien).
     *
     * @return MorphTo<Model, $this>
     */
    public function handledRef(): MorphTo
    {
        return $this->morphTo('handled_ref');
    }
}
