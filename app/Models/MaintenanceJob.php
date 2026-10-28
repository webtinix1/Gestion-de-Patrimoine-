<?php

namespace App\Models;

use App\Enums\AssetStatus;
use App\Enums\MaintenanceNature;
use App\Enums\MaintenanceResult;
use App\Enums\MaintenanceStatus;
use App\Enums\ProviderType;
use App\Models\Concerns\RecordsAuthors;
use App\Models\Scopes\MovementPerimeterScope;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * @property int $id
 * @property string $reference
 * @property int $asset_id
 * @property MaintenanceNature $nature
 * @property string $reason
 * @property Carbon $starts_on
 * @property Carbon|null $ends_on
 * @property ProviderType $provider_type
 * @property string|null $provider_name
 * @property int|null $cost
 * @property string|null $cost_reference
 * @property MaintenanceResult|null $result
 * @property int|null $condition_after_id
 * @property AssetStatus|null $status_after
 * @property MaintenanceStatus $status
 * @property string|null $notes
 * @property int $lock_version
 * @property int|null $updated_by
 * @property Carbon|null $updated_at
 */
#[Fillable([
    'reference', 'asset_id', 'nature', 'reason', 'starts_on', 'ends_on', 'provider_type', 'provider_name',
    'cost', 'cost_reference', 'result', 'condition_after_id', 'status_after', 'status', 'notes',
])]
class MaintenanceJob extends Model
{
    use RecordsAuthors;

    protected static function booted(): void
    {
        // RG-81 : une intervention est visible si son bien l'est.
        static::addGlobalScope(new MovementPerimeterScope);

        static::updating(function (MaintenanceJob $job): void {
            if ($job->getOriginal('status') === MaintenanceStatus::Done) {
                throw new LogicException('Une intervention terminée n\'est plus modifiable.');
            }

            // RG-86 : toute modification change la version de l'intervention.
            $job->setAttribute('lock_version', (int) $job->getOriginal('lock_version') + 1);
        });
        static::deleting(fn () => throw new LogicException('Une intervention ne se supprime pas.'));
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'nature' => MaintenanceNature::class,
            'provider_type' => ProviderType::class,
            'result' => MaintenanceResult::class,
            'status_after' => AssetStatus::class,
            'status' => MaintenanceStatus::class,
            'starts_on' => 'date',
            'ends_on' => 'date',
            'cost' => 'integer',
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
    public function conditionAfter(): BelongsTo
    {
        return $this->belongsTo(AssetCondition::class, 'condition_after_id');
    }

    public function isOpen(): bool
    {
        return $this->status->isOpen();
    }
}
