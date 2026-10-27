<?php

use App\Actions\Assets\CreateAsset;
use App\Enums\AssetStatus;
use App\Enums\Depreciability;
use App\Enums\FiscalYearStatus;
use App\Models\Asset;
use App\Models\AssetCategory;
use App\Models\AssetCondition;
use App\Models\Department;
use App\Models\DepreciationLine;
use App\Models\DepreciationSchedule;
use App\Models\FiscalYear;
use App\Models\Site;

/**
 * Cas de référence (RG-40), lus dans le fichier qui fait foi.
 *
 * @return array<string, mixed>
 */
function depreciationCase(string $id): array
{
    $json = json_decode((string) file_get_contents(base_path('tests/Fixtures/amortissements.json')), true, flags: JSON_THROW_ON_ERROR);

    foreach ($json['cas'] as $case) {
        if ($case['id'] === $id) {
            return $case;
        }
    }

    throw new RuntimeException('Cas inconnu : '.$id);
}

/**
 * @param  array<string, mixed>  $overrides
 */
function createDepreciableAsset(array $overrides = [], ?AssetCategory $category = null): Asset
{
    $category ??= AssetCategory::query()->where('prefix', 'INF')->first()
        ?? AssetCategory::factory()->create([
            'name' => 'Matériel informatique', 'prefix' => 'INF', 'depreciable' => Depreciability::Yes, 'default_duration_years' => 3,
        ]);

    return app(CreateAsset::class)([
        'designation' => 'Ordinateur portable',
        'asset_category_id' => $category->id,
        'acquired_on' => '2024-03-18',
        'in_service_on' => '2024-03-18',
        'acquisition_cost' => 540000,
        'site_id' => Site::factory()->create()->id,
        'department_id' => Department::factory()->create()->id,
        'asset_condition_id' => AssetCondition::factory()->create()->id,
        'status' => AssetStatus::InService->value,
        ...$overrides,
    ]);
}

function fiscalYear(int $year, FiscalYearStatus $status): FiscalYear
{
    $fiscalYear = new FiscalYear([
        'year' => $year,
        'label' => (string) $year,
        'starts_on' => $year.'-01-01',
        'ends_on' => $year.'-12-31',
        'status' => $status,
    ]);
    $fiscalYear->save();

    return $fiscalYear;
}

function currentSchedule(Asset $asset): ?DepreciationSchedule
{
    return DepreciationSchedule::query()->where('asset_id', $asset->id)->where('is_current', true)->with('lines')->first();
}

/**
 * Lignes du plan courant au format du fichier de cas.
 *
 * @return list<array<string, int|null>>
 */
function scheduleLines(Asset $asset, string $unit = 'jours'): array
{
    return currentSchedule($asset)?->lines->map(fn (DepreciationLine $line): array => [
        'exercice' => $line->fiscal_year,
        $unit => $unit === 'mois' ? $line->months : $line->days,
        'dotation' => $line->amount,
        'cumul' => $line->accumulated,
        'vnc' => $line->net_book_value,
    ])->values()->all() ?? [];
}
