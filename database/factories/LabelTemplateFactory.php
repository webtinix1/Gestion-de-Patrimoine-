<?php

namespace Database\Factories;

use App\Enums\LabelCodeType;
use App\Enums\LabelSheetLayout;
use App\Models\LabelTemplate;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<LabelTemplate>
 */
class LabelTemplateFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => 'Modèle '.fake()->unique()->word(),
            'width_mm' => 70,
            'height_mm' => 37,
            'sheet_layout' => LabelSheetLayout::A4ThreeByEight,
            'code_type' => LabelCodeType::Qr,
            'show_designation' => true,
            'show_department' => true,
            'is_default' => false,
        ];
    }

    public function roll(): static
    {
        return $this->state(fn (array $attributes) => [
            'width_mm' => 50,
            'height_mm' => 25,
            'sheet_layout' => LabelSheetLayout::Roll,
            'code_type' => LabelCodeType::Code128,
        ]);
    }
}
