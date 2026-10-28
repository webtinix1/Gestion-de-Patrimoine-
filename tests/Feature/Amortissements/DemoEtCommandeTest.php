<?php

use App\Enums\FiscalYearStatus;
use App\Models\Asset;
use App\Models\DepreciationLine;
use App\Models\DepreciationSchedule;
use App\Models\FiscalYear;
use Database\Seeders\DemoSeeder;
use Database\Seeders\DepreciationDemoSeeder;
use Database\Seeders\ReferenceSeeder;

require_once __DIR__.'/helpers.php';

beforeEach(function () {
    $this->seed(ReferenceSeeder::class);
    $this->seed(DemoSeeder::class);
});

it('clôture 2023 à 2025 et ouvre 2026 dans la démonstration', function () {
    expect(FiscalYear::query()->orderBy('year')->get()->mapWithKeys(fn (FiscalYear $year) => [$year->year => $year->status])->all())
        ->toBe([2023 => FiscalYearStatus::Closed, 2024 => FiscalYearStatus::Closed, 2025 => FiscalYearStatus::Closed, 2026 => FiscalYearStatus::Open]);
});

it('génère un plan courant pour chaque bien amortissable de démonstration', function () {
    $depreciable = Asset::query()->where('is_depreciable', true)->whereNotNull('in_service_on')->count();

    expect($depreciable)->toBeGreaterThan(100)
        ->and(DepreciationSchedule::query()->where('is_current', true)->count())->toBe($depreciable)
        ->and(DepreciationLine::query()->where('fiscal_year', '<', 2026)->where('is_locked', false)->count())->toBe(0)
        ->and(DepreciationLine::query()->where('fiscal_year', '>=', 2026)->where('is_locked', true)->count())->toBe(0);
});

it('montre exactement les cas A2, A5 (avec la sortie du lot 10) et A3 sur les biens de démonstration', function () {
    expect(scheduleLines(Asset::query()->where('number', 'PAT-000125')->sole()))->toBe(depreciationCase('A2')['attendu'])
        ->and(scheduleLines(Asset::query()->where('number', 'PAT-000034')->sole()))->toBe(depreciationCase('A5')['attendu'])
        ->and(scheduleLines(Asset::query()->where('number', 'PAT-001248')->sole()))->toBe(depreciationCase('A3')['attendu']);

    // RG-38 : la version validée à la clôture de 2025 (sans la sortie) est conservée ; la sortie a créé la version 2.
    $asset = Asset::query()->where('number', 'PAT-000034')->sole();
    $validated = DepreciationSchedule::query()->where('asset_id', $asset->id)->where('version', 1)->with('lines')->sole();

    expect($validated->lines->sum('amount'))->toBe(4800000)
        ->and($validated->lines->firstWhere('fiscal_year', 2026)->amount)->toBe(600000)
        ->and($validated->isValidated())->toBeTrue()
        ->and(currentSchedule($asset)->version)->toBe(2);
});

it('est idempotent, comme la commande de recalcul', function () {
    $snapshot = DepreciationLine::query()->orderBy('id')->get()->map->getAttributes()->all();
    $schedules = DepreciationSchedule::query()->count();

    $this->seed(DepreciationDemoSeeder::class);

    $this->artisan('patrimoine:recalculer-plans')
        ->expectsOutputToContain('Aucun changement')
        ->assertSuccessful();

    $this->artisan('patrimoine:recalculer-plans')
        ->expectsOutputToContain('Aucun changement')
        ->assertSuccessful();

    expect(DepreciationLine::query()->orderBy('id')->get()->map->getAttributes()->all())->toBe($snapshot)
        ->and(DepreciationSchedule::query()->count())->toBe($schedules);
});

it('recalcule les exercices ouverts d\'un plan modifié hors écran, et eux seuls', function () {
    $asset = Asset::query()->where('number', 'PAT-000125')->sole();
    $locked = currentSchedule($asset)->lines->where('is_locked', true)->map->getAttributes()->values()->all();

    Asset::query()->whereKey($asset->id)->update(['acquisition_cost' => 600000]);

    $this->artisan('patrimoine:recalculer-plans')->expectsOutputToContain('1 plan(s) mis à jour')->assertSuccessful();

    $schedule = currentSchedule($asset);

    // Plan validé à la clôture de 2025 : conservé, une version 2 reprend les lignes figées.
    expect($schedule->version)->toBe(2)
        ->and($schedule->lines->where('is_locked', true)->map(fn (DepreciationLine $line) => $line->only(['fiscal_year', 'amount', 'accumulated']))->values()->all())
        ->toBe(array_map(fn (array $line) => ['fiscal_year' => $line['fiscal_year'], 'amount' => $line['amount'], 'accumulated' => $line['accumulated']], $locked))
        ->and($schedule->lines->sum('amount'))->toBe(600000);

    $this->artisan('patrimoine:recalculer-plans')->expectsOutputToContain('Aucun changement')->assertSuccessful();
});
