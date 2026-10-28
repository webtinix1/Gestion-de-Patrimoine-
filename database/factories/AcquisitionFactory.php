<?php

namespace Database\Factories;

use App\Enums\AcquisitionStatus;
use App\Enums\FundingSource;
use App\Models\Acquisition;
use App\Models\Department;
use App\Models\Site;
use App\Models\Supplier;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Pour les tests. Les écrans passent par App\Actions\Acquisitions\CreateAcquisition.
 *
 * @extends Factory<Acquisition>
 */
class AcquisitionFactory extends Factory
{
    private static int $sequence = 0;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'reference' => sprintf('TST-%03d', ++self::$sequence),
            'acquired_on' => now()->toDateString(),
            'supplier_id' => Supplier::factory(),
            'invoice_reference' => 'FAC-'.fake()->numerify('####'),
            'funding_source' => FundingSource::OwnFunds,
            'site_id' => Site::factory(),
            'department_id' => Department::factory(),
            'total_amount' => 0,
            'status' => AcquisitionStatus::Draft,
        ];
    }

    public function validated(): static
    {
        return $this->state(fn () => ['status' => AcquisitionStatus::Validated]);
    }
}
