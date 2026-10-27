<?php

namespace App\Models;

use App\Enums\MovementType;
use App\Models\Scopes\MovementPerimeterScope;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * @property int $id
 * @property int $asset_id
 * @property MovementType $type
 * @property Carbon $moved_on
 * @property string|null $reason
 * @property string|null $notes
 * @property int|null $from_site_id
 * @property int|null $from_department_id
 * @property int|null $from_service_id
 * @property int|null $from_room_id
 * @property int|null $from_holder_id
 * @property int $to_site_id
 * @property int $to_department_id
 * @property int|null $to_service_id
 * @property int|null $to_room_id
 * @property int|null $to_holder_id
 * @property string|null $batch_uuid
 * @property int|null $created_by
 * @property Carbon|null $created_at
 */
#[Fillable([
    'asset_id', 'type', 'moved_on', 'reason', 'notes',
    'from_site_id', 'from_department_id', 'from_service_id', 'from_room_id', 'from_holder_id',
    'to_site_id', 'to_department_id', 'to_service_id', 'to_room_id', 'to_holder_id',
    'batch_uuid', 'created_by',
])]
class Movement extends Model
{
    protected static function booted(): void
    {
        // RG-81 : un mouvement est visible si son bien l'est.
        static::addGlobalScope(new MovementPerimeterScope);

        // RG-21 : l'affectation précédente n'est jamais écrasée, un mouvement ne se modifie ni ne se supprime.
        static::updating(fn () => throw new LogicException('Un mouvement ne peut pas être modifié.'));
        static::deleting(fn () => throw new LogicException('Un mouvement ne peut pas être supprimé.'));
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['type' => MovementType::class, 'moved_on' => 'date'];
    }

    /**
     * @return BelongsTo<Asset, $this>
     */
    public function asset(): BelongsTo
    {
        return $this->belongsTo(Asset::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * @return BelongsTo<Site, $this>
     */
    public function fromSite(): BelongsTo
    {
        return $this->belongsTo(Site::class, 'from_site_id');
    }

    /**
     * @return BelongsTo<Department, $this>
     */
    public function fromDepartment(): BelongsTo
    {
        return $this->belongsTo(Department::class, 'from_department_id');
    }

    /**
     * @return BelongsTo<Service, $this>
     */
    public function fromService(): BelongsTo
    {
        return $this->belongsTo(Service::class, 'from_service_id');
    }

    /**
     * @return BelongsTo<Room, $this>
     */
    public function fromRoom(): BelongsTo
    {
        return $this->belongsTo(Room::class, 'from_room_id');
    }

    /**
     * @return BelongsTo<Holder, $this>
     */
    public function fromHolder(): BelongsTo
    {
        return $this->belongsTo(Holder::class, 'from_holder_id');
    }

    /**
     * @return BelongsTo<Site, $this>
     */
    public function toSite(): BelongsTo
    {
        return $this->belongsTo(Site::class, 'to_site_id');
    }

    /**
     * @return BelongsTo<Department, $this>
     */
    public function toDepartment(): BelongsTo
    {
        return $this->belongsTo(Department::class, 'to_department_id');
    }

    /**
     * @return BelongsTo<Service, $this>
     */
    public function toService(): BelongsTo
    {
        return $this->belongsTo(Service::class, 'to_service_id');
    }

    /**
     * @return BelongsTo<Room, $this>
     */
    public function toRoom(): BelongsTo
    {
        return $this->belongsTo(Room::class, 'to_room_id');
    }

    /**
     * @return BelongsTo<Holder, $this>
     */
    public function toHolder(): BelongsTo
    {
        return $this->belongsTo(Holder::class, 'to_holder_id');
    }

    /**
     * Affectation de départ ou d'arrivée en texte : « Siège · Louange · Chorale · Salle de culte · Ruth Bouanga ».
     */
    public function assignmentLabel(string $side): string
    {
        $prefix = $side === 'from' ? 'from' : 'to';
        $names = [];

        foreach (['site', 'department', 'service', 'room', 'holder'] as $element) {
            if ($this->getAttribute($prefix.'_'.$element.'_id') === null) {
                continue;
            }

            // Un élément que le périmètre de l'utilisateur ne laisse pas voir n'est pas affiché comme un vide.
            $part = $this->getRelationValue($prefix.ucfirst($element));
            $names[] = $part instanceof Model ? (string) $part->getAttribute('name') : '(hors de votre périmètre)';
        }

        return $names === [] ? '—' : implode(' · ', $names);
    }
}
