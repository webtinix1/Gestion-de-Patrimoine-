<?php

namespace App\Models;

use App\Enums\LabelCodeType;
use App\Enums\LabelSheetLayout;
use App\Models\Concerns\RecordsAuthors;
use Database\Factories\LabelTemplateFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * RG-95, RG-96 : modèle d'étiquette (contenu, format, type de code).
 *
 * @property int $id
 * @property string $name
 * @property float $width_mm
 * @property float $height_mm
 * @property LabelSheetLayout $sheet_layout
 * @property LabelCodeType $code_type
 * @property string|null $header_text
 * @property bool $show_designation
 * @property bool $show_department
 * @property bool $show_site
 * @property bool $show_room
 * @property bool $show_logo
 * @property bool $is_default
 */
#[Fillable([
    'name', 'width_mm', 'height_mm', 'sheet_layout', 'code_type', 'header_text',
    'show_designation', 'show_department', 'show_site', 'show_room', 'show_logo', 'is_default',
])]
class LabelTemplate extends Model
{
    /** @use HasFactory<LabelTemplateFactory> */
    use HasFactory, RecordsAuthors;

    protected $attributes = [
        'show_designation' => true,
        'show_department' => true,
        'show_site' => false,
        'show_room' => false,
        'show_logo' => false,
        'is_default' => false,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'width_mm' => 'float',
            'height_mm' => 'float',
            'sheet_layout' => LabelSheetLayout::class,
            'code_type' => LabelCodeType::class,
            'show_designation' => 'boolean',
            'show_department' => 'boolean',
            'show_site' => 'boolean',
            'show_room' => 'boolean',
            'show_logo' => 'boolean',
            'is_default' => 'boolean',
        ];
    }

    public function summary(): string
    {
        return self::mm($this->width_mm).' × '.self::mm($this->height_mm).' mm · '.$this->code_type->getLabel();
    }

    private static function mm(float $value): string
    {
        return rtrim(rtrim(number_format($value, 1, ',', ''), '0'), ',');
    }
}
