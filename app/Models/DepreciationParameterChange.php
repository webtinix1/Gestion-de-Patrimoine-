<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * RG-37 : historique d'un changement de paramètre d'amortissement, jamais modifié.
 *
 * @property int $id
 * @property int $asset_id
 * @property string $batch_uuid
 * @property string $parameter
 * @property string|null $old_value
 * @property string|null $new_value
 * @property string $reason
 * @property int|null $effective_year
 * @property int|null $effective_fiscal_year_id
 * @property int|null $changed_by
 * @property Carbon $changed_at
 */
#[Fillable([
    'asset_id', 'batch_uuid', 'parameter', 'old_value', 'new_value', 'reason', 'effective_year',
    'effective_fiscal_year_id', 'changed_by', 'changed_at',
])]
class DepreciationParameterChange extends Model
{
    /**
     * @var array<string, string>
     */
    public const array PARAMETER_LABELS = [
        'acquisition_cost' => 'Valeur d\'origine',
        'residual_value' => 'Valeur résiduelle',
        'duration_years' => 'Durée (années)',
        'in_service_on' => 'Date de mise en service',
        'depreciation_method' => 'Méthode',
        'declining_coefficient' => 'Coefficient dégressif',
    ];

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('L\'historique des paramètres d\'amortissement ne se modifie pas.'));
        static::deleting(fn () => throw new LogicException('L\'historique des paramètres d\'amortissement ne se supprime pas.'));
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'effective_year' => 'integer',
            'changed_at' => 'datetime',
        ];
    }

    public function parameterLabel(): string
    {
        return self::PARAMETER_LABELS[$this->parameter] ?? $this->parameter;
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
    public function changer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'changed_by');
    }
}
