<?php

namespace App\Models;

use App\Enums\AssetStatus;
use App\Enums\InventoryLineResult;
use App\Enums\InventoryResolution;
use App\Enums\InventoryStatus;
use App\Models\Scopes\InventoryLinePerimeterScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * @property int $id
 * @property int $inventory_id
 * @property int|null $asset_id
 * @property bool $expected
 * @property int|null $expected_site_id
 * @property int|null $expected_room_id
 * @property int|null $expected_department_id
 * @property int|null $expected_condition_id
 * @property AssetStatus|null $expected_status
 * @property InventoryLineResult $result
 * @property int|null $found_site_id
 * @property int|null $found_room_id
 * @property int|null $found_department_id
 * @property int|null $found_condition_id
 * @property string|null $description
 * @property string|null $notes
 * @property int|null $checked_by
 * @property Carbon|null $checked_at
 * @property int $lock_version
 * @property InventoryResolution|null $resolution
 * @property string|null $resolution_ref_type
 * @property int|null $resolution_ref_id
 * @property int|null $resolved_by
 * @property Carbon|null $resolved_at
 * @property Carbon|null $updated_at
 * @property-read Asset|null $asset
 * @property-read Inventory $inventory
 */
class InventoryLine extends Model
{
    /** Colonnes du constat et du snapshot, figées par la clôture (RG-48). */
    public const array FINDING_COLUMNS = [
        'result', 'found_site_id', 'found_room_id', 'found_department_id', 'found_condition_id',
        'description', 'notes', 'checked_by', 'checked_at',
        'expected_site_id', 'expected_room_id', 'expected_department_id', 'expected_condition_id', 'expected_status',
    ];

    // Aucune affectation de masse : les actions renseignent chaque champ explicitement.
    protected $guarded = ['*'];

    protected $attributes = ['result' => 'pending', 'expected' => true, 'lock_version' => 0];

    protected static function booted(): void
    {
        static::addGlobalScope(new InventoryLinePerimeterScope);

        static::updating(function (InventoryLine $line): void {
            if ($line->isDirty(self::FINDING_COLUMNS)) {
                // RG-48 : la clôture fige les résultats.
                $status = Inventory::query()->withoutGlobalScopes()->whereKey($line->inventory_id)->value('status');

                if ($status === InventoryStatus::Closed->value || $status === InventoryStatus::Closed) {
                    throw new LogicException('Les résultats d\'un inventaire clôturé sont figés.');
                }

                // RG-47 : toute saisie change la version de la ligne.
                $line->setAttribute('lock_version', (int) $line->getOriginal('lock_version') + 1);
            }

            if ($line->getOriginal('resolution') !== null && $line->isDirty(['resolution', 'resolution_ref_type', 'resolution_ref_id'])) {
                throw new LogicException('Une régularisation enregistrée ne se modifie pas.');
            }
        });

        static::deleting(fn () => throw new LogicException('Une ligne d\'inventaire ne se supprime pas.'));
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'expected' => 'boolean',
            'expected_status' => AssetStatus::class,
            'result' => InventoryLineResult::class,
            'resolution' => InventoryResolution::class,
            'checked_at' => 'datetime',
            'resolved_at' => 'datetime',
            'lock_version' => 'integer',
        ];
    }

    public function isChecked(): bool
    {
        return $this->result !== InventoryLineResult::Pending;
    }

    /**
     * @return BelongsTo<Inventory, $this>
     */
    public function inventory(): BelongsTo
    {
        return $this->belongsTo(Inventory::class)->withoutGlobalScopes();
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
    public function checker(): BelongsTo
    {
        return $this->belongsTo(User::class, 'checked_by');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function resolver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by');
    }

    /**
     * RG-48 : opération de régularisation (mouvement, bien créé, sortie…).
     *
     * @return MorphTo<Model, $this>
     */
    public function resolutionRef(): MorphTo
    {
        return $this->morphTo('resolution_ref');
    }

    /**
     * @return BelongsTo<Site, $this>
     */
    public function expectedSite(): BelongsTo
    {
        return $this->belongsTo(Site::class, 'expected_site_id')->withoutGlobalScopes();
    }

    /**
     * @return BelongsTo<Room, $this>
     */
    public function expectedRoom(): BelongsTo
    {
        return $this->belongsTo(Room::class, 'expected_room_id')->withoutGlobalScopes();
    }

    /**
     * @return BelongsTo<Department, $this>
     */
    public function expectedDepartment(): BelongsTo
    {
        return $this->belongsTo(Department::class, 'expected_department_id')->withoutGlobalScopes();
    }

    /**
     * @return BelongsTo<AssetCondition, $this>
     */
    public function expectedCondition(): BelongsTo
    {
        return $this->belongsTo(AssetCondition::class, 'expected_condition_id');
    }

    /**
     * @return BelongsTo<Site, $this>
     */
    public function foundSite(): BelongsTo
    {
        return $this->belongsTo(Site::class, 'found_site_id')->withoutGlobalScopes();
    }

    /**
     * @return BelongsTo<Room, $this>
     */
    public function foundRoom(): BelongsTo
    {
        return $this->belongsTo(Room::class, 'found_room_id')->withoutGlobalScopes();
    }

    /**
     * @return BelongsTo<Department, $this>
     */
    public function foundDepartment(): BelongsTo
    {
        return $this->belongsTo(Department::class, 'found_department_id')->withoutGlobalScopes();
    }

    /**
     * @return BelongsTo<AssetCondition, $this>
     */
    public function foundCondition(): BelongsTo
    {
        return $this->belongsTo(AssetCondition::class, 'found_condition_id');
    }
}
