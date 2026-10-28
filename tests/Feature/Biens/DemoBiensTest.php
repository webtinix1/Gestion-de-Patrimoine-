<?php

use App\Actions\Assets\CreateAsset;
use App\Enums\AssetStatus;
use App\Models\Asset;
use App\Models\Holder;
use App\Models\Room;
use App\Models\Site;
use Database\Seeders\AssetsDemoSeeder;
use Database\Seeders\DemoSeeder;
use Database\Seeders\ReferenceSeeder;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $this->seed(ReferenceSeeder::class);
    $this->seed(DemoSeeder::class);
});

function demoAsset(string $number): Asset
{
    return Asset::query()->with(['category', 'site', 'department', 'room', 'condition'])->where('number', $number)->firstOrFail();
}

it('crée environ 300 biens de démonstration', function () {
    expect(Asset::query()->count())->toBeBetween(290, 310);
});

it('répartit les biens sur les sites, les catégories et les états, avec les seuls statuts autorisés', function () {
    $usedSites = Asset::query()->distinct()->pluck('site_id');
    $statuses = Asset::query()->pluck('status')->unique()->map(fn (AssetStatus $status) => $status->value)->values()->all();

    expect($usedSites->count())->toBe(Site::query()->where('is_active', true)->count())
        ->and(Asset::query()->distinct()->count('asset_category_id'))->toBe(10)
        ->and(Asset::query()->distinct()->count('asset_condition_id'))->toBe(6)
        ->and($statuses)->toEqualCanonicalizing([AssetStatus::InService->value, AssetStatus::InStock->value, AssetStatus::OutOfService->value, AssetStatus::OnLoan->value, AssetStatus::InMaintenance->value])
        ->and(Asset::query()->where('status', AssetStatus::InService)->count())->toBeGreaterThan(250)
        ->and(Asset::query()->whereNotNull('disposed_at')->count())->toBe(0);
});

it('place chaque bien dans un local du site indiqué', function () {
    $misplaced = Asset::query()->get()->filter(fn (Asset $asset) => $asset->room_id !== null && Room::query()->find($asset->room_id)?->site_id !== $asset->site_id);

    expect($misplaced)->toHaveCount(0);
});

it('crée des responsables, dont certains liés à un compte', function () {
    expect(Holder::query()->whereNotNull('user_id')->count())->toBeGreaterThan(3)
        ->and(Holder::query()->whereNull('user_id')->count())->toBeGreaterThan(0)
        ->and(Asset::query()->whereNotNull('holder_id')->count())->toBeGreaterThan(100);
});

it('crée le PAT-000125 du cas A2', function () {
    $asset = demoAsset('PAT-000125');

    expect($asset->designation)->toBe('Ordinateur portable')
        ->and($asset->brand)->toBe('HP')
        ->and($asset->model)->toBe('EliteBook 840')
        ->and($asset->category->prefix)->toBe('INF')
        ->and($asset->acquisition_cost)->toBe(540000)
        ->and($asset->duration_years)->toBe(3)
        ->and($asset->is_depreciable)->toBeTrue()
        ->and($asset->acquired_on->toDateString())->toBe('2024-03-18')
        ->and($asset->in_service_on?->toDateString())->toBe('2024-03-18')
        ->and($asset->site->code)->toBe('SIE')
        ->and($asset->department->code)->toBe('ADM')
        ->and($asset->room?->name)->toBe('Bureau administratif');
});

it('crée le PAT-000034 du cas A5, hors service mais encore en service au patrimoine', function () {
    $asset = demoAsset('PAT-000034');

    expect($asset->designation)->toBe('Groupe électrogène')
        ->and($asset->brand)->toBe('SDMO')
        ->and($asset->model)->toBe('15 kVA')
        ->and($asset->category->prefix)->toBe('ELE')
        ->and($asset->acquisition_cost)->toBe(4800000)
        ->and($asset->duration_years)->toBe(8)
        ->and($asset->in_service_on?->toDateString())->toBe('2022-06-15')
        ->and($asset->site->code)->toBe('SIE')
        ->and($asset->department->code)->toBe('LOG')
        ->and($asset->room?->name)->toBe('Local technique')
        ->and($asset->condition->label)->toBe('Hors service')
        ->and($asset->status)->toBe(AssetStatus::InService)
        ->and($asset->disposed_at)->toBeNull();
});

it('crée le PAT-001248 du cas A3', function () {
    $asset = demoAsset('PAT-001248');

    expect($asset->designation)->toBe('Vidéoprojecteur')
        ->and($asset->brand)->toBe('Epson')
        ->and($asset->model)->toBe('EB-X51')
        ->and($asset->category->prefix)->toBe('AUD')
        ->and($asset->acquisition_cost)->toBe(385000)
        ->and($asset->duration_years)->toBe(5)
        ->and($asset->acquired_on->toDateString())->toBe('2026-10-22')
        ->and($asset->in_service_on?->toDateString())->toBe('2026-10-26')
        ->and($asset->site->code)->toBe('SIE')
        ->and($asset->department->code)->toBe('COM')
        ->and($asset->room?->name)->toBe('Salle de culte');
});

it('crée le PAT-000087, console de mixage Yamaha', function () {
    $asset = demoAsset('PAT-000087');

    expect($asset->designation)->toBe('Console de mixage')
        ->and($asset->brand)->toBe('Yamaha')
        ->and($asset->model)->toBe('MG16XU')
        ->and($asset->category->prefix)->toBe('SON')
        ->and($asset->acquisition_cost)->toBe(450000)
        ->and($asset->site->code)->toBe('SIE')
        ->and($asset->department->code)->toBe('JEU')
        ->and($asset->room?->name)->toBe('Salle polyvalente');
});

it('applique le seuil d\'immobilisation aux catégories « selon seuil » (RG-30)', function () {
    $threshold = Asset::query()->whereHas('category', fn ($query) => $query->where('prefix', 'BUR'))->get();
    $below = $threshold->where('acquisition_cost', '<', 100000);
    $above = $threshold->where('acquisition_cost', '>=', 100000);

    expect($below->every(fn (Asset $asset) => ! $asset->is_depreciable))->toBeTrue()
        ->and($above->every(fn (Asset $asset) => $asset->is_depreciable))->toBeTrue()
        ->and($below->count())->toBeGreaterThan(0)
        ->and($above->count())->toBeGreaterThan(0);
});

it('aligne le compteur : le prochain bien créé prend la suite du plus grand numéro', function () {
    $highest = Asset::query()->pluck('number')->map(fn (string $number) => (int) substr($number, -6))->max();

    expect($highest)->toBe(1248);

    $reference = demoAsset('PAT-000125');
    $asset = app(CreateAsset::class)([
        'designation' => 'Chaise',
        'asset_category_id' => $reference->asset_category_id,
        'acquired_on' => '2026-10-27',
        'site_id' => $reference->site_id,
        'department_id' => $reference->department_id,
        'asset_condition_id' => $reference->asset_condition_id,
        'status' => AssetStatus::InService->value,
        'acquisition_cost' => 50000,
    ]);

    expect($asset->number)->toBe('PAT-001249');
});

it('est idempotent : relancer le seeder ne crée aucun doublon', function () {
    $assets = Asset::query()->count();
    $holders = Holder::query()->count();
    $sequence = DB::table('asset_number_sequences')->where('scope', 'global')->value('last_value');

    $this->seed(AssetsDemoSeeder::class);
    $this->seed(DemoSeeder::class);

    expect(Asset::query()->count())->toBe($assets)
        ->and(Holder::query()->count())->toBe($holders)
        ->and(DB::table('asset_number_sequences')->where('scope', 'global')->value('last_value'))->toBe($sequence)
        ->and(Asset::query()->distinct()->count('number'))->toBe($assets);
});
