<?php

namespace App\Models;

use App\Enums\BorrowerType;
use App\Enums\LoanDirection;
use App\Enums\LoanStatus;
use App\Models\Concerns\RecordsAuthors;
use App\Models\Scopes\LoanPerimeterScope;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * @property int $id
 * @property string $reference
 * @property LoanDirection $direction
 * @property BorrowerType $borrower_type
 * @property string $borrower_name
 * @property string|null $borrower_contact
 * @property int|null $department_id
 * @property Carbon $out_on
 * @property Carbon $due_on
 * @property Carbon|null $returned_on
 * @property int|null $responsible_id
 * @property string|null $notes
 * @property LoanStatus $status
 * @property int|null $created_by
 */
#[Fillable([
    'reference', 'direction', 'borrower_type', 'borrower_name', 'borrower_contact', 'department_id',
    'out_on', 'due_on', 'returned_on', 'responsible_id', 'notes', 'status',
])]
class Loan extends Model
{
    use RecordsAuthors;

    protected static function booted(): void
    {
        static::addGlobalScope(new LoanPerimeterScope);

        // RG-56 : un prêt rendu garde son historique tel quel ; rien ne se supprime.
        static::updating(function (Loan $loan): void {
            if ($loan->getOriginal('status') === LoanStatus::Returned) {
                throw new LogicException('Un prêt rendu n\'est plus modifiable.');
            }
        });
        static::deleting(fn () => throw new LogicException('Un prêt ne se supprime pas.'));
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'direction' => LoanDirection::class,
            'borrower_type' => BorrowerType::class,
            'status' => LoanStatus::class,
            'out_on' => 'date',
            'due_on' => 'date',
            'returned_on' => 'date',
        ];
    }

    /**
     * @return HasMany<LoanItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(LoanItem::class);
    }

    /**
     * @return BelongsTo<Department, $this>
     */
    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class)->withoutGlobalScopes();
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function responsible(): BelongsTo
    {
        return $this->belongsTo(User::class, 'responsible_id');
    }

    public function isOngoing(): bool
    {
        return $this->status === LoanStatus::Ongoing;
    }

    public function isOverdue(): bool
    {
        return $this->isOngoing() && $this->due_on->lt(today());
    }

    // Situation affichée dans les listes : retard, échéance proche, état au retour.
    public function situationLabel(): string
    {
        if (! $this->isOngoing()) {
            return $this->items->contains(fn (LoanItem $item): bool => $item->isDegraded()) ? 'Rendu, état dégradé' : 'Rendu, conforme';
        }

        if ($this->isOverdue()) {
            return $this->daysLate().' j de retard';
        }

        $days = (int) today()->diffInDays($this->due_on, false);

        return match (true) {
            $days === 0 => 'Dernier jour',
            $days === 1 => 'Demain',
            $days <= 7 => 'Dans '.$days.' j',
            default => 'En cours',
        };
    }

    public function situationColor(): string
    {
        if (! $this->isOngoing()) {
            return $this->items->contains(fn (LoanItem $item): bool => $item->isDegraded()) ? 'warning' : 'success';
        }

        if ($this->isOverdue()) {
            return 'danger';
        }

        return (int) today()->diffInDays($this->due_on, false) <= 7 ? 'warning' : 'info';
    }

    public function daysLate(): int
    {
        return $this->isOverdue() ? (int) $this->due_on->diffInDays(today()) : 0;
    }
}
