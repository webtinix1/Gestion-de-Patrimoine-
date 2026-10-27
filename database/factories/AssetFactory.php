<?php

namespace Database\Factories;

use App\Enums\AssetStatus;
use App\Models\Asset;
use App\Models\AssetCategory;
use App\Models\AssetCondition;
use App\Models\Department;
use App\Models\Site;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Pour les tests. Les écrans et les seeders passent par App\Actions\Assets\CreateAsset,
 * seule voie qui attribue un numéro patrimonial.
 *
 * @extends Factory<Asset>
 */
class AssetFactory extends Factory
{
    private static int $sequence = 900000;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'designation' => fake()->randomElement(['Chaise', 'Bureau', 'Ordinateur portable', 'Imprimante', 'Micro sans fil']),
            'asset_category_id' => AssetCategory::factory(),
            'acquired_on' => fake()->dateTimeBetween('-5 years')->format('Y-m-d'),
            'acquisition_cost' => fake()->numberBetween(5, 900) * 1000,
            'site_id' => Site::factory(),
            'department_id' => Department::factory(),
            'asset_condition_id' => AssetCondition::factory(),
            'status' => AssetStatus::InService,
        ];
    }

    public function configure(): static
    {
        return $this->afterMaking(function (Asset $asset): void {
            if (blank($asset->number)) {
                $asset->forceFill(['number' => 'TST-'.str_pad((string) ++self::$sequence, 6, '0', STR_PAD_LEFT)]);
            }
        });
    }

    public function disposed(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => AssetStatus::Retired,
        ])->afterMaking(fn (Asset $asset) => $asset->forceFill(['disposed_at' => now()->toDateString()]));
    }
}
