<?php

namespace Database\Factories;

use App\Enums\Depreciability;
use App\Enums\DepreciationMethod;
use App\Models\AssetCategory;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AssetCategory>
 */
class AssetCategoryFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => 'Catégorie '.fake()->unique()->word(),
            'prefix' => strtoupper(fake()->unique()->lexify('???')),
            'depreciable' => Depreciability::Yes,
            'default_method' => DepreciationMethod::Linear,
            'default_duration_years' => 5,
            'is_active' => true,
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => ['is_active' => false]);
    }
}
