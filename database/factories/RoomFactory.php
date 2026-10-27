<?php

namespace Database\Factories;

use App\Enums\RoomType;
use App\Models\Room;
use App\Models\Site;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Room>
 */
class RoomFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'site_id' => Site::factory(),
            'name' => 'Salle '.fake()->unique()->word(),
            'code' => strtoupper(fake()->unique()->bothify('R??-##')),
            'type' => RoomType::Office,
            'is_active' => true,
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => ['is_active' => false]);
    }
}
