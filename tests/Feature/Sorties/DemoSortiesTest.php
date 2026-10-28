<?php

use App\Enums\AssetStatus;
use App\Enums\DisposalAccountingStatus;
use App\Enums\DisposalReason;
use App\Models\Asset;
use App\Models\Disposal;
use Database\Seeders\DemoSeeder;
use Database\Seeders\DisposalsDemoSeeder;
use Database\Seeders\ReferenceSeeder;
use Illuminate\Support\Carbon;

require_once __DIR__.'/helpers.php';

beforeEach(function () {
    Carbon::setTestNow('2026-10-28 10:00:00');
    $this->seed(ReferenceSeeder::class);
    $this->seed(DemoSeeder::class);
});

afterEach(fn () => Carbon::setTestNow());

it('crée 3 sorties de démonstration, une validée et deux à valider', function () {
    $disposals = Disposal::query()->orderBy('reference')->get();

    expect($disposals->pluck('reference')->all())->toBe(['SRT-2026-001', 'SRT-2026-002', 'SRT-2026-003'])
        ->and($disposals->pluck('reason')->all())->toBe([DisposalReason::Donation, DisposalReason::Loss, DisposalReason::Retirement])
        ->and($disposals->where('accounting_status', DisposalAccountingStatus::Validated))->toHaveCount(1)
        ->and($disposals->where('accounting_status', DisposalAccountingStatus::Pending))->toHaveCount(2)
        ->and($disposals->first()->validator?->username)->toBe('m.loubaki')
        ->and($disposals->first()->asset->status)->toBe(AssetStatus::TransferredOut);
});

it('sort PAT-000034 le 26/10/2026 et retrouve exactement le cas A5', function () {
    $asset = Asset::query()->where('number', 'PAT-000034')->sole();
    $disposal = $asset->disposal;

    expect($asset->status)->toBe(AssetStatus::Retired)
        ->and($asset->disposed_at?->toDateString())->toBe('2026-10-26')
        ->and($disposal->reference)->toBe('SRT-2026-003')
        ->and([$disposal->original_value, $disposal->accumulated_depreciation, $disposal->net_book_value])->toBe([4800000, 2620000, 2180000])
        ->and(scheduleLines($asset))->toBe(depreciationCase('A5')['attendu'])
        ->and(currentSchedule($asset)->lines->firstWhere('fiscal_year', 2026)->amount)->toBe(493333);
});

it('est idempotent', function () {
    $snapshot = Disposal::query()->orderBy('id')->get()->map->getAttributes()->all();

    $this->seed(DisposalsDemoSeeder::class);

    expect(Disposal::query()->orderBy('id')->get()->map->getAttributes()->all())->toBe($snapshot);
});
