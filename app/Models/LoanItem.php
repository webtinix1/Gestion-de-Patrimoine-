<?php

namespace App\Models;

use App\Enums\AssetStatus;
use App\Models\Scopes\LoanItemPerimeterScope;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * @property int $id
 * @property int $loan_id
 * @property int|null $asset_id
 * @property string|null $external_designation
 * @property string|null $external_reference
 * @property int|null $external_value
 * @property int $condition_out_id
 * @property int|null $condition_back_id
 * @property AssetStatus|null $previous_status
 * @property string|null $return_notes
 */
#[Fillable([
    'loan_id', 'asset_id', 'external_designation', 'external_reference', 'external_value',
    'condition_out_id', 'condition_back_id', 'previous_status', 'return_notes',
])]
class LoanItem extends Model
{
    protected static function booted(): void
    {
        static::addGlobalScope(new LoanItemPerimeterScope);

        // Le constat de départ et de retour d'une ligne ne se réécrit pas une fois le retour enregistré.
        static::updating(function (LoanItem $item): void {
            if ($item->getOriginal('condition_back_id') !== null) {
                throw new LogicException('Le retour de cette ligne est déjà enregistré.');
            }
        });
        static::deleting(fn () => throw new LogicException('Une ligne de prêt ne se supprime pas.'));
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['previous_status' => AssetStatus::class, 'external_value' => 'integer'];
    }

    /**
     * @return BelongsTo<Loan, $this>
     */
    public function loan(): BelongsTo
    {
        return $this->belongsTo(Loan::class);
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
    public function conditionOut(): BelongsTo
    {
        return $this->belongsTo(AssetCondition::class, 'condition_out_id');
    }

    /**
     * @return BelongsTo<AssetCondition, $this>
     */
    public function conditionBack(): BelongsTo
    {
        return $this->belongsTo(AssetCondition::class, 'condition_back_id');
    }

    public function label(): string
    {
        return $this->asset_id !== null
            ? trim(($this->asset->number ?? '—').' · '.($this->asset?->label() ?? ''))
            : trim(($this->external_reference ?? '').' · '.($this->external_designation ?? ''));
    }

    // RG-57 : l'ordre des états est le champ « sort » des états.
    public function isDegraded(): bool
    {
        if ($this->condition_back_id === null) {
            return false;
        }

        return (int) $this->conditionBack?->sort > (int) $this->conditionOut?->sort;
    }
}
