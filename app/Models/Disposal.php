<?php

namespace App\Models;

use App\Enums\AssetStatus;
use App\Enums\DisposalAccountingStatus;
use App\Enums\DisposalReason;
use App\Models\Concerns\RecordsAuthors;
use App\Models\Scopes\MovementPerimeterScope;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphToMany;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * Sortie du patrimoine (RG-50 à RG-54). Les valeurs à la date de sortie sont figées (RG-39).
 *
 * @property int $id
 * @property string $reference
 * @property int $asset_id
 * @property Carbon $disposed_on
 * @property DisposalReason $reason
 * @property string $decided_by
 * @property string|null $decision_reference
 * @property string|null $buyer_or_beneficiary
 * @property int|null $sale_price
 * @property string|null $notes
 * @property int $original_value
 * @property int $accumulated_depreciation
 * @property int $net_book_value
 * @property AssetStatus $previous_status
 * @property DisposalAccountingStatus $accounting_status
 * @property int|null $accounting_validated_by
 * @property Carbon|null $accounting_validated_at
 * @property int|null $created_by
 * @property Carbon|null $created_at
 */
#[Fillable([
    'reference', 'asset_id', 'disposed_on', 'reason', 'decided_by', 'decision_reference', 'buyer_or_beneficiary',
    'sale_price', 'notes', 'original_value', 'accumulated_depreciation', 'net_book_value', 'previous_status',
])]
class Disposal extends Model
{
    use RecordsAuthors;

    /**
     * Seuls changements admis après l'enregistrement : la validation comptable (RG-54).
     *
     * @var list<string>
     */
    private const array VALIDATION_FIELDS = ['accounting_status', 'accounting_validated_by', 'accounting_validated_at', 'updated_by', 'updated_at'];

    protected $attributes = ['accounting_status' => 'pending'];

    protected static function booted(): void
    {
        // RG-81 : une sortie est visible si son bien l'est (le scope du bien s'applique dans whereHas).
        static::addGlobalScope(new MovementPerimeterScope);

        // RG-53 : une sortie ne s'annule ni ne se modifie ; seule la validation comptable, une fois, est admise.
        static::updating(function (Disposal $disposal): void {
            $unexpected = array_diff(array_keys($disposal->getDirty()), self::VALIDATION_FIELDS);

            if ($unexpected !== []
                || $disposal->getOriginal('accounting_status') !== DisposalAccountingStatus::Pending
                || $disposal->accounting_status !== DisposalAccountingStatus::Validated) {
                throw new LogicException('Une sortie du patrimoine ne peut être ni modifiée ni annulée.');
            }
        });

        static::deleting(fn () => throw new LogicException('Une sortie du patrimoine ne se supprime pas.'));
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'disposed_on' => 'date',
            'reason' => DisposalReason::class,
            'previous_status' => AssetStatus::class,
            'accounting_status' => DisposalAccountingStatus::class,
            'accounting_validated_at' => 'datetime',
            'sale_price' => 'integer',
            'original_value' => 'integer',
            'accumulated_depreciation' => 'integer',
            'net_book_value' => 'integer',
        ];
    }

    public function isValidated(): bool
    {
        return $this->accounting_status === DisposalAccountingStatus::Validated;
    }

    /**
     * Q8 : plus-value (positive) ou moins-value (négative) indicative d'une cession, sans écriture comptable.
     */
    public function indicativeGain(): ?int
    {
        return $this->reason === DisposalReason::Sale && $this->sale_price !== null
            ? $this->sale_price - $this->net_book_value
            : null;
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
    public function validator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'accounting_validated_by');
    }

    /**
     * RG-51 : justificatifs de la sortie (aussi rattachés au bien).
     *
     * @return MorphToMany<Document, $this>
     */
    public function documents(): MorphToMany
    {
        return $this->morphToMany(Document::class, 'documentable')->withTimestamps();
    }
}
