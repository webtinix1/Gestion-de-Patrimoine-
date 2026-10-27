<?php

namespace App\Models;

use App\Enums\DepreciationMethod;
use App\Models\Concerns\RecordsAuthors;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * Plan d'amortissement d'un bien, par version (RG-38).
 *
 * @property int $id
 * @property int $asset_id
 * @property int $version
 * @property DepreciationMethod $method
 * @property int $original_value
 * @property int $base_amount
 * @property int $duration_years
 * @property string $rate
 * @property string|null $declining_coefficient
 * @property int $residual_value
 * @property Carbon $starts_on
 * @property Carbon $ends_on
 * @property int|null $recalculated_from_year
 * @property int|null $validated_by
 * @property Carbon|null $validated_at
 * @property bool $is_current
 */
#[Fillable([
    'asset_id', 'version', 'method', 'original_value', 'base_amount', 'duration_years', 'rate', 'declining_coefficient',
    'residual_value', 'starts_on', 'ends_on', 'recalculated_from_year', 'is_current',
])]
class DepreciationSchedule extends Model
{
    use RecordsAuthors;

    /**
     * Champs qui définissent le calcul : figés dès que la version est validée (RG-38).
     *
     * @var list<string>
     */
    public const array CALCULATION_FIELDS = [
        'method', 'original_value', 'base_amount', 'duration_years', 'rate', 'declining_coefficient',
        'residual_value', 'starts_on', 'ends_on', 'recalculated_from_year',
    ];

    protected static function booted(): void
    {
        static::updating(function (DepreciationSchedule $schedule): void {
            if ($schedule->getOriginal('validated_at') !== null && $schedule->isDirty(self::CALCULATION_FIELDS)) {
                throw new LogicException('Une version validée du plan est conservée telle quelle (RG-38).');
            }
        });

        static::deleting(fn () => throw new LogicException('Un plan d\'amortissement ne se supprime pas.'));
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'version' => 'integer',
            'method' => DepreciationMethod::class,
            'original_value' => 'integer',
            'base_amount' => 'integer',
            'duration_years' => 'integer',
            'rate' => 'decimal:4',
            'declining_coefficient' => 'decimal:4',
            'residual_value' => 'integer',
            'starts_on' => 'date',
            'ends_on' => 'date',
            'recalculated_from_year' => 'integer',
            'validated_at' => 'datetime',
            'is_current' => 'boolean',
        ];
    }

    public function isValidated(): bool
    {
        return $this->validated_at !== null;
    }

    /**
     * Un plan « engagé » a produit un effet comptable : version validée ou exercice figé.
     */
    public function isEngaged(): bool
    {
        return $this->isValidated() || $this->lines()->where('is_locked', true)->exists();
    }

    /**
     * @return BelongsTo<Asset, $this>
     */
    public function asset(): BelongsTo
    {
        return $this->belongsTo(Asset::class);
    }

    /**
     * @return HasMany<DepreciationLine, $this>
     */
    public function lines(): HasMany
    {
        return $this->hasMany(DepreciationLine::class)->orderBy('fiscal_year');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function validator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'validated_by');
    }
}
