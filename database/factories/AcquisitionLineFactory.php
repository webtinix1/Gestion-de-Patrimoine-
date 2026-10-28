<?php

namespace Database\Factories;

use App\Models\Acquisition;
use App\Models\AcquisitionLine;
use App\Models\AssetCategory;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AcquisitionLine>
 */
class AcquisitionLineFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $quantity = fake()->numberBetween(1, 5);
        $price = fake()->numberBetween(1, 100) * 5000;

        return [
            'acquisition_id' => Acquisition::factory(),
            'designation' => fake()->randomElement(['Ordinateur portable', 'Onduleur', 'Chaise']),
            'asset_category_id' => AssetCategory::factory(),
            'quantity' => $quantity,
            'unit_price' => $price,
            'tracked_individually' => true,
            'line_total' => $quantity * $price,
        ];
    }
}
