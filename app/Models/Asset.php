<?php

namespace App\Models;

use App\Enums\AssetStatus;
use App\Enums\DepreciationMethod;
use App\Enums\FundingSource;
use App\Models\Concerns\HasPerimeter;
use App\Models\Concerns\RecordsAuthors;
use Database\Factories\AssetFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphToMany;
use Illuminate\Support\Carbon;
use LogicException;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * @property int $id
 * @property string $number
 * @property string $designation
 * @property int $asset_category_id
 * @property string|null $brand
 * @property string|null $model
 * @property string|null $serial_number
 * @property string|null $description
 * @property string|null $photo_path
 * @property Carbon $acquired_on
 * @property Carbon|null $in_service_on
 * @property int|null $supplier_id
 * @property string|null $invoice_reference
 * @property int|null $acquisition_cost
 * @property FundingSource|null $funding_source
 * @property int|null $acquisition_line_id
 * @property int $site_id
 * @property int $department_id
 * @property int|null $service_id
 * @property int|null $room_id
 * @property int|null $holder_id
 * @property Carbon|null $assigned_since
 * @property int $asset_condition_id
 * @property AssetStatus $status
 * @property string|null $notes
 * @property Carbon|null $disposed_at
 * @property bool $is_depreciable
 * @property bool $depreciation_forced_off
 * @property DepreciationMethod|null $depreciation_method
 * @property int|null $duration_years
 * @property int $residual_value
 * @property string|null $asset_account
 * @property string|null $depreciation_account
 * @property int $lock_version
 * @property int|null $updated_by
 * @property Carbon|null $updated_at
 */
#[Fillable([
    'designation', 'asset_category_id', 'brand', 'model', 'serial_number', 'description', 'photo_path',
    'acquired_on', 'in_service_on', 'supplier_id', 'invoice_reference', 'acquisition_cost', 'funding_source', 'acquisition_line_id',
    'site_id', 'department_id', 'service_id', 'room_id', 'holder_id', 'assigned_since',
    'asset_condition_id', 'status', 'notes',
    'is_depreciable', 'depreciation_forced_off', 'depreciation_method', 'duration_years', 'residual_value',
    'asset_account', 'depreciation_account',
])]
class Asset extends Model
{
    /** @use HasFactory<AssetFactory> */
    use HasFactory, HasPerimeter, LogsActivity, RecordsAuthors;

    protected $attributes = ['residual_value' => 0, 'is_depreciable' => false, 'depreciation_forced_off' => false];

    protected static function booted(): void
    {
        // RG-04 : le numéro n'est jamais modifié.
        static::updating(function (Asset $asset): void {
            if ($asset->isDirty('number')) {
                throw new LogicException('Le numéro patrimonial ne peut pas être modifié.');
            }

            // RG-52, RG-53 : une fiche sortie est en lecture seule et sa sortie ne s'annule pas, par aucun chemin.
            if ($asset->getOriginal('disposed_at') !== null
                && array_diff(array_keys($asset->getDirty()), ['updated_at', 'updated_by', 'lock_version']) !== []) {
                throw new LogicException('Un bien sorti du patrimoine est en lecture seule : sa fiche ne peut plus être modifiée.');
            }

            // RG-86 : toute modification, quelle que soit l'opération, change la version de la fiche.
            $asset->setAttribute('lock_version', (int) $asset->getOriginal('lock_version') + 1);
        });

        // RG-50 : un bien quitte le patrimoine par une sortie, il n'est jamais supprimé.
        static::deleting(fn () => throw new LogicException('Un bien ne se supprime pas : il sort du patrimoine par une sortie.'));
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'acquired_on' => 'date',
            'in_service_on' => 'date',
            'assigned_since' => 'date',
            'disposed_at' => 'date',
            'acquisition_cost' => 'integer',
            'residual_value' => 'integer',
            'duration_years' => 'integer',
            'funding_source' => FundingSource::class,
            'status' => AssetStatus::class,
            'depreciation_method' => DepreciationMethod::class,
            'is_depreciable' => 'boolean',
            'depreciation_forced_off' => 'boolean',
        ];
    }

    public function perimeterColumns(): array
    {
        return ['site' => 'site_id', 'department' => 'department_id'];
    }

    public function isDisposed(): bool
    {
        return $this->disposed_at !== null;
    }

    public function label(): string
    {
        return trim($this->designation.' '.trim(($this->brand ?? '').' '.($this->model ?? '')));
    }

    /**
     * @return BelongsTo<AssetCategory, $this>
     */
    public function category(): BelongsTo
    {
        return $this->belongsTo(AssetCategory::class, 'asset_category_id');
    }

    /**
     * @return BelongsTo<AssetCondition, $this>
     */
    public function condition(): BelongsTo
    {
        return $this->belongsTo(AssetCondition::class, 'asset_condition_id');
    }

    /**
     * @return BelongsTo<Supplier, $this>
     */
    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
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

    /**
     * @return BelongsTo<Service, $this>
     */
    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class);
    }

    /**
     * @return BelongsTo<Room, $this>
     */
    public function room(): BelongsTo
    {
        return $this->belongsTo(Room::class);
    }

    /**
     * @return BelongsTo<Holder, $this>
     */
    public function holder(): BelongsTo
    {
        return $this->belongsTo(Holder::class);
    }

    /**
     * RG-21 : historique des affectations.
     *
     * @return HasMany<Movement, $this>
     */
    public function movements(): HasMany
    {
        return $this->hasMany(Movement::class);
    }

    /**
     * RG-50 : sortie du patrimoine (une au plus).
     *
     * @return HasOne<Disposal, $this>
     */
    public function disposal(): HasOne
    {
        return $this->hasOne(Disposal::class);
    }

    /**
     * RG-55 : lignes de prêt du bien.
     *
     * @return HasMany<LoanItem, $this>
     */
    public function loanItems(): HasMany
    {
        return $this->hasMany(LoanItem::class);
    }

    /**
     * RG-60 : interventions de maintenance du bien.
     *
     * @return HasMany<MaintenanceJob, $this>
     */
    public function maintenanceJobs(): HasMany
    {
        return $this->hasMany(MaintenanceJob::class);
    }

    /**
     * RG-63 : signalements du bien.
     *
     * @return HasMany<AssetReport, $this>
     */
    public function reports(): HasMany
    {
        return $this->hasMany(AssetReport::class);
    }

    /**
     * RG-14 : un bien a plusieurs documents.
     *
     * @return MorphToMany<Document, $this>
     */
    public function documents(): MorphToMany
    {
        return $this->morphToMany(Document::class, 'documentable')->withTimestamps();
    }

    // RG-70 : création et modification d'un bien tracées, avec avant et après.
    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('biens')
            ->logFillable()
            ->logOnly(['number', 'disposed_at'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs()
            ->setDescriptionForEvent(fn (string $event): string => match ($event) {
                'created' => 'Création d\'un bien',
                'updated' => 'Modification d\'une fiche',
                default => $event,
            });
    }
}
