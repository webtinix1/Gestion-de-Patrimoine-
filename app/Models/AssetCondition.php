<?php

namespace App\Models;

use App\Models\Concerns\IsReferential;
use App\Models\Concerns\RecordsAuthors;
use Database\Factories\AssetConditionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * RG-11 : état physique du bien, liste paramétrable.
 *
 * @property int $id
 * @property string $label
 * @property string $color
 * @property int $sort
 * @property bool $is_active
 */
#[Fillable(['label', 'color', 'sort', 'is_active'])]
class AssetCondition extends Model
{
    /** @use HasFactory<AssetConditionFactory> */
    use HasFactory, IsReferential, RecordsAuthors;

    public const array COLORS = [
        'success' => 'Vert',
        'primary' => 'Bleu pétrole',
        'info' => 'Bleu',
        'warning' => 'Orange',
        'danger' => 'Rouge',
        'gray' => 'Gris',
    ];

    protected $attributes = ['is_active' => true, 'color' => 'gray', 'sort' => 0];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'sort' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    public function usageRelations(): array
    {
        return [];
    }
}
