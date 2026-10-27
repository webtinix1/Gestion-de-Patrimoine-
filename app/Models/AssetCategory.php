<?php

namespace App\Models;

use App\Enums\Depreciability;
use App\Enums\DepreciationMethod;
use App\Models\Concerns\IsReferential;
use App\Models\Concerns\RecordsAuthors;
use Database\Factories\AssetCategoryFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property string $name
 * @property string $prefix
 * @property Depreciability $depreciable
 * @property DepreciationMethod $default_method
 * @property int|null $default_duration_years
 * @property string|null $asset_account
 * @property string|null $depreciation_account
 * @property string|null $expense_account
 * @property bool $is_active
 */
#[Fillable([
    'name',
    'prefix',
    'depreciable',
    'default_method',
    'default_duration_years',
    'asset_account',
    'depreciation_account',
    'expense_account',
    'is_active',
])]
class AssetCategory extends Model
{
    /** @use HasFactory<AssetCategoryFactory> */
    use HasFactory, IsReferential, RecordsAuthors;

    protected $attributes = ['is_active' => true, 'depreciable' => 'yes', 'default_method' => 'linear'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'depreciable' => Depreciability::class,
            'default_method' => DepreciationMethod::class,
            'default_duration_years' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    // RG-31 : le taux linéaire est calculé, 100 / durée.
    public static function linearRate(?int $durationYears): ?float
    {
        if (! $durationYears) {
            return null;
        }

        return round(100 / $durationYears, 2);
    }

    public function usageRelations(): array
    {
        return [];
    }
}
