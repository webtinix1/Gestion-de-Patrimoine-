<?php

namespace App\Models;

use Database\Factories\AcquisitionLineFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property int $acquisition_id
 * @property string $designation
 * @property int $asset_category_id
 * @property int $quantity
 * @property int $unit_price
 * @property bool $tracked_individually
 * @property int $line_total
 */
#[Fillable(['acquisition_id', 'designation', 'asset_category_id', 'quantity', 'unit_price', 'tracked_individually', 'line_total'])]
class AcquisitionLine extends Model
{
    /** @use HasFactory<AcquisitionLineFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
            'unit_price' => 'integer',
            'line_total' => 'integer',
            'tracked_individually' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<Acquisition, $this>
     */
    public function acquisition(): BelongsTo
    {
        return $this->belongsTo(Acquisition::class);
    }

    /**
     * @return BelongsTo<AssetCategory, $this>
     */
    public function category(): BelongsTo
    {
        return $this->belongsTo(AssetCategory::class, 'asset_category_id');
    }

    /**
     * @return HasMany<Asset, $this>
     */
    public function assets(): HasMany
    {
        return $this->hasMany(Asset::class, 'acquisition_line_id');
    }
}
