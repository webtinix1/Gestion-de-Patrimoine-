<?php

use App\Enums\MovementType;
use App\Models\Asset;
use App\Models\Movement;
use Database\Seeders\DemoSeeder;
use Database\Seeders\MovementsDemoSeeder;
use Database\Seeders\ReferenceSeeder;

beforeEach(function () {
    $this->seed(ReferenceSeeder::class);
    $this->seed(DemoSeeder::class);
});

it('donne à chaque bien de démonstration son mouvement d\'affectation initiale (RG-22)', function () {
    $assets = Asset::query()->count();

    expect($assets)->toBeGreaterThanOrEqual(300)
        ->and(Movement::query()->where('type', MovementType::Initial)->count())->toBe($assets)
        ->and(Asset::query()->whereDoesntHave('movements', fn ($query) => $query->where('type', MovementType::Initial))->count())->toBe(0);

    $asset = Asset::query()->where('number', 'PAT-000125')->sole();
    $initial = Movement::query()->where('asset_id', $asset->id)->where('type', MovementType::Initial)->sole();

    expect($initial->to_department_id)->toBe($asset->department_id)
        ->and($initial->to_site_id)->toBe($asset->site_id)
        ->and($initial->from_site_id)->toBeNull();
});

it('est idempotent : un second passage ne duplique rien', function () {
    $before = Movement::query()->count();

    $this->seed(MovementsDemoSeeder::class);
    $this->seed(ReferenceSeeder::class);
    $this->seed(DemoSeeder::class);

    expect(Movement::query()->count())->toBe($before);
});

it('crée quelques transferts de démonstration cohérents avec les fiches', function () {
    $transfers = Movement::query()->where('type', '!=', MovementType::Initial)->get();

    expect($transfers)->not->toBeEmpty();

    foreach ($transfers as $movement) {
        $latest = Movement::query()->where('asset_id', $movement->asset_id)->orderByDesc('moved_on')->orderByDesc('id')->first();
        $asset = Asset::query()->find($movement->asset_id);

        expect($latest?->to_room_id)->toBe($asset?->room_id)
            ->and($latest?->to_holder_id)->toBe($asset?->holder_id);
    }
});
