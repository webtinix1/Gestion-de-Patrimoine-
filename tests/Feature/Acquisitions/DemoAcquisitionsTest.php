<?php

use App\Actions\Acquisitions\CreateAcquisition;
use App\Actions\Acquisitions\ValidateAcquisition;
use App\Enums\AcquisitionStatus;
use App\Models\Acquisition;
use App\Models\Asset;
use App\Models\User;
use Database\Seeders\AcquisitionsDemoSeeder;
use Database\Seeders\DemoSeeder;
use Database\Seeders\ReferenceSeeder;

beforeEach(function () {
    $this->seed(ReferenceSeeder::class);
    $this->seed(DemoSeeder::class);
});

it('charge les acquisitions de la maquette sans créer de fiche supplémentaire', function () {
    expect(Acquisition::query()->count())->toBe(4)
        ->and(Acquisition::query()->where('status', AcquisitionStatus::Validated)->count())->toBe(1)
        ->and(Acquisition::query()->where('reference', 'ACQ-2026-039')->firstOrFail()->total_amount)->toBe(2_280_000)
        ->and(Asset::query()->count())->toBeBetween(290, 310)
        ->and(Asset::query()->where('number', 'PAT-001248')->firstOrFail()->acquisition_line_id)->not->toBeNull();
});

it('est idempotent', function () {
    $this->seed(AcquisitionsDemoSeeder::class);

    expect(Acquisition::query()->count())->toBe(4);
});

it('valide l\'acquisition de la maquette en créant PAT-001249 à PAT-001254, puis la suivante reçoit ACQ-2026-040', function () {
    $this->actingAs(User::query()->where('username', 'admin')->firstOrFail());

    $assets = app(ValidateAcquisition::class)(Acquisition::query()->where('reference', 'ACQ-2026-039')->firstOrFail());

    expect($assets->pluck('number')->all())->toBe(['PAT-001249', 'PAT-001250', 'PAT-001251', 'PAT-001252', 'PAT-001253', 'PAT-001254']);

    $next = app(CreateAcquisition::class)([
        'acquired_on' => '2026-10-27',
        'supplier_id' => $assets->first()->supplier_id,
        'site_id' => $assets->first()->site_id,
        'department_id' => $assets->first()->department_id,
        'lines' => [['designation' => 'X', 'asset_category_id' => $assets->first()->asset_category_id, 'quantity' => 1, 'unit_price' => 1000, 'tracked_individually' => false]],
    ]);

    expect($next->reference)->toBe('ACQ-2026-040');
});
