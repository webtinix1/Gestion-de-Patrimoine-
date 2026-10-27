<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * Ligne d'un plan d'amortissement : un exercice.
 *
 * @property int $id
 * @property int $depreciation_schedule_id
 * @property int $fiscal_year
 * @property int|null $days
 * @property int|null $months
 * @property int $base
 * @property string|null $rate
 * @property int $amount
 * @property int $accumulated
 * @property int $net_book_value
 * @property bool $is_locked
 */
#[Fillable([
    'depreciation_schedule_id', 'fiscal_year', 'days', 'months', 'base', 'rate', 'amount', 'accumulated',
    'net_book_value', 'is_locked',
])]
class DepreciationLine extends Model
{
    protected static function booted(): void
    {
        // RG-36 : une ligne figée n'est jamais modifiée ni supprimée, quel que soit le recalcul.
        static::updating(function (DepreciationLine $line): void {
            if ($line->getOriginal('is_locked')) {
                throw new LogicException('La ligne de l\'exercice '.$line->fiscal_year.' est figée : elle ne peut pas être modifiée.');
            }
        });

        static::deleting(function (DepreciationLine $line): void {
            if ($line->is_locked) {
                throw new LogicException('La ligne de l\'exercice '.$line->fiscal_year.' est figée : elle ne peut pas être supprimée.');
            }
        });
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'fiscal_year' => 'integer',
            'days' => 'integer',
            'months' => 'integer',
            'base' => 'integer',
            'rate' => 'decimal:4',
            'amount' => 'integer',
            'accumulated' => 'integer',
            'net_book_value' => 'integer',
            'is_locked' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<DepreciationSchedule, $this>
     */
    public function schedule(): BelongsTo
    {
        return $this->belongsTo(DepreciationSchedule::class, 'depreciation_schedule_id');
    }

    /**
     * @return BelongsTo<FiscalYear, $this>
     */
    public function fiscalYear(): BelongsTo
    {
        return $this->belongsTo(FiscalYear::class, 'fiscal_year', 'year');
    }
}
