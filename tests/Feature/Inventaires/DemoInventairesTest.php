<?php

use App\Enums\InventoryLineResult;
use App\Enums\InventoryResolution;
use App\Enums\InventoryStatus;
use App\Models\Asset;
use App\Models\Inventory;
use App\Models\InventoryLine;
use Database\Seeders\DemoSeeder;
use Database\Seeders\InventoriesDemoSeeder;
use Database\Seeders\ReferenceSeeder;

beforeEach(function () {
    $this->seed(ReferenceSeeder::class);
    $this->seed(DemoSeeder::class);
});

it('livre les deux inventaires de la maquette : un clôturé avec écarts régularisés en partie, un en cours', function () {
    $closed = Inventory::query()->where('reference', 'INV-2026-004')->sole();
    $current = Inventory::query()->where('reference', 'INV-2026-005')->sole();
    $closedLines = InventoryLine::query()->where('inventory_id', $closed->id)->get();
    $currentLines = InventoryLine::query()->where('inventory_id', $current->id)->get();

    expect($closed->status)->toBe(InventoryStatus::Closed)
        ->and($closed->scopeLabel())->toBe('Siège · tout le site')
        ->and($closed->manager->username)->toBe('a.okemba')
        ->and($closedLines->where('result', InventoryLineResult::Pending))->toHaveCount(0)
        ->and($closedLines->where('result', InventoryLineResult::Missing))->toHaveCount(3)
        ->and($closedLines->where('result', InventoryLineResult::Unregistered))->toHaveCount(1)
        ->and($closedLines->whereNotNull('resolution')->pluck('resolution')->map->value->sort()->values()->all())
        ->toBe([InventoryResolution::ConditionUpdate->value, InventoryResolution::Found->value, InventoryResolution::Transfer->value])
        ->and($current->status)->toBe(InventoryStatus::InProgress)
        ->and($current->scopeLabel())->toBe('Annexe Talangaï · tout le site')
        ->and($current->participants)->toHaveCount(3)
        ->and($currentLines->where('result', InventoryLineResult::Pending)->count())->toBeGreaterThan(0)
        ->and($currentLines->where('result', InventoryLineResult::Unregistered))->toHaveCount(1);
});

it('ne crée aucune fiche et reste idempotent', function () {
    $assets = Asset::query()->count();
    $lines = InventoryLine::query()->count();

    $this->seed(InventoriesDemoSeeder::class);
    $this->seed(DemoSeeder::class);

    expect(Asset::query()->count())->toBe($assets)
        ->and(InventoryLine::query()->count())->toBe($lines)
        ->and(Inventory::query()->count())->toBe(2)
        ->and(Asset::query()->where('number', 'PAT-001249')->exists())->toBeFalse();
});
