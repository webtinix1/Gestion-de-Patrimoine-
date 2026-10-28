<?php

namespace Database\Factories;

use App\Models\Holder;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Holder>
 */
class HolderFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'phone' => fake()->phoneNumber(),
            'is_active' => true,
        ];
    }
}
