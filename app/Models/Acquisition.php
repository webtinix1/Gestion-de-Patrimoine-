<?php

namespace App\Models;

use App\Enums\AcquisitionStatus;
use App\Enums\FundingSource;
use App\Models\Concerns\HasPerimeter;
use App\Models\Concerns\RecordsAuthors;
use Database\Factories\AcquisitionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Database\Eloquent\Relations\MorphToMany;
use Illuminate\Support\Carbon;
use LogicException;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * @property int $id
 * @property string $reference
 * @property Carbon $acquired_on
 * @property int|null $supplier_id
 * @property string|null $invoice_reference
 * @property FundingSource|null $funding_source
 * @property int $site_id
 * @property int $department_id
 * @property int $total_amount
 * @property int $lock_version
 * @property int|null $updated_by
 * @property Carbon|null $updated_at
 * @property AcquisitionStatus $status
 * @property Carbon|null $validated_at
 * @property int|null $validated_by
 */
#[Fillable(['acquired_on', 'supplier_id', 'invoice_reference', 'funding_source', 'site_id', 'department_id', 'total_amount'])]
class Acquisition extends Model
{
    /** @use HasFactory<AcquisitionFactory> */
    use HasFactory, HasPerimeter, LogsActivity, RecordsAuthors;

    protected $attributes = ['status' => 'draft', 'total_amount' => 0];

    protected static function booted(): void
    {
        // RG-15 : une acquisition validée a créé ses fiches, elle ne change plus et ne se supprime plus.
        static::updating(function (Acquisition $acquisition): void {
            if ($acquisition->getOriginal('status') === AcquisitionStatus::Validated) {
                throw new LogicException('Une acquisition validée n\'est plus modifiable.');
            }

            // RG-86 : toute modification change la version du brouillon.
            $acquisition->setAttribute('lock_version', (int) $acquisition->getOriginal('lock_version') + 1);
        });

        static::deleting(function (Acquisition $acquisition): void {
            if ($acquisition->isValidated()) {
                throw new LogicException('Une acquisition validée ne se supprime pas.');
            }
        });
    }

    /**
     * @return array<string, mixed>
     */
    protected function casts(): array
    {
        return [
            'acquired_on' => 'date',
            'total_amount' => 'integer',
            'funding_source' => FundingSource::class,
            'status' => AcquisitionStatus::class,
            'validated_at' => 'datetime',
        ];
    }

    public function perimeterColumns(): array
    {
        return ['site' => 'site_id', 'department' => 'department_id'];
    }

    public function isDraft(): bool
    {
        return $this->status === AcquisitionStatus::Draft;
    }

    public function isValidated(): bool
    {
        return $this->status === AcquisitionStatus::Validated;
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
     * @return BelongsTo<User, $this>
     */
    public function validator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'validated_by');
    }

    /**
     * @return HasMany<AcquisitionLine, $this>
     */
    public function lines(): HasMany
    {
        return $this->hasMany(AcquisitionLine::class)->orderBy('id');
    }

    /**
     * Fiches créées par la validation.
     *
     * @return HasManyThrough<Asset, AcquisitionLine, $this>
     */
    public function assets(): HasManyThrough
    {
        return $this->hasManyThrough(Asset::class, AcquisitionLine::class, 'acquisition_id', 'acquisition_line_id');
    }

    /**
     * RG-65 : justificatifs (facture, bon de livraison, PV).
     *
     * @return MorphToMany<Document, $this>
     */
    public function documents(): MorphToMany
    {
        return $this->morphToMany(Document::class, 'documentable')->withTimestamps();
    }

    // RG-70 : création et modification tracées ; la validation a sa propre entrée (avec les numéros créés).
    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('acquisitions')
            ->logFillable()
            ->logOnly(['reference', 'status'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs()
            ->setDescriptionForEvent(fn (string $event): string => match ($event) {
                'created' => 'Création d\'une acquisition',
                'updated' => 'Modification d\'une acquisition',
                'deleted' => 'Suppression d\'un brouillon d\'acquisition',
                default => $event,
            });
    }
}
