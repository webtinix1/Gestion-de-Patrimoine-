<?php

namespace App\Models;

use App\Enums\FiscalYearStatus;
use App\Models\Concerns\RecordsAuthors;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * Exercice comptable (année civile par défaut, Q5).
 *
 * @property int $id
 * @property int $year
 * @property string $label
 * @property Carbon $starts_on
 * @property Carbon $ends_on
 * @property FiscalYearStatus $status
 * @property int|null $closed_by
 * @property Carbon|null $closed_at
 * @property array<string, mixed>|null $closing_notes
 */
#[Fillable(['year', 'label', 'starts_on', 'ends_on', 'status'])]
class FiscalYear extends Model
{
    use RecordsAuthors;

    protected static function booted(): void
    {
        // RG-43 : la clôture ne peut pas être annulée ; un exercice clôturé ne change plus.
        static::updating(function (FiscalYear $fiscalYear): void {
            if ($fiscalYear->getOriginal('status') === FiscalYearStatus::Closed) {
                throw new LogicException('Un exercice clôturé ne peut plus être modifié.');
            }
        });

        static::deleting(fn () => throw new LogicException('Un exercice comptable ne se supprime pas.'));
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'year' => 'integer',
            'starts_on' => 'date',
            'ends_on' => 'date',
            'status' => FiscalYearStatus::class,
            'closed_at' => 'datetime',
            'closing_notes' => 'array',
        ];
    }

    public function isClosed(): bool
    {
        return $this->status === FiscalYearStatus::Closed;
    }

    /**
     * @param  Builder<static>  $query
     */
    public function scopeNotClosed(Builder $query): void
    {
        $query->where('status', '!=', FiscalYearStatus::Closed->value);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function closer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'closed_by');
    }
}
