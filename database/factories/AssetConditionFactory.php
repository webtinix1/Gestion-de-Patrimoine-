<?php

namespace Database\Factories;

use App\Models\AssetCondition;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AssetCondition>
 */
class AssetConditionFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'label' => 'État '.fake()->unique()->word(),
            'color' => 'gray',
            'sort' => fake()->numberBetween(1, 100),
            'is_active' => true,
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => ['is_active' => false]);
    }
}
