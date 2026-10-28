<?php

use App\Actions\Disposals\RecordDisposal;
use App\Enums\Depreciability;
use App\Enums\DisposalReason;
use App\Enums\FiscalYearStatus;
use App\Models\Asset;
use App\Models\AssetCategory;
use App\Models\DepreciationLine;
use App\Models\DepreciationSchedule;
use App\Models\Disposal;
use App\Models\FiscalYear;

require_once __DIR__.'/../Amortissements/helpers.php';

/**
 * Exercices de la démonstration : 2023 à 2025 clôturés, 2026 ouvert (RG-36).
 */
function disposalFiscalYears(): void
{
    foreach ([2023, 2024, 2025] as $year) {
        if (! FiscalYear::query()->where('year', $year)->exists()) {
            fiscalYear($year, FiscalYearStatus::Closed);
        }
    }

    if (! FiscalYear::query()->where('year', 2026)->exists()) {
        fiscalYear(2026, FiscalYearStatus::Open);
    }
}

/**
 * Bien du cas A5 (groupe électrogène, 4 800 000, 8 ans, mise en service le 15/06/2022).
 *
 * @param  array<string, mixed>  $overrides
 */
function a5Asset(array $overrides = []): Asset
{
    $category = AssetCategory::query()->where('prefix', 'ELE')->first()
        ?? AssetCategory::factory()->create(['name' => 'Matériel électrique', 'prefix' => 'ELE', 'depreciable' => Depreciability::Yes, 'default_duration_years' => 8]);

    return createDepreciableAsset([
        'designation' => 'Groupe électrogène',
        'acquisition_cost' => 4800000,
        'acquired_on' => '2022-06-15',
        'in_service_on' => '2022-06-15',
        ...$overrides,
    ], $category);
}

/**
 * @param  array<string, mixed>  $data
 */
function recordDisposal(Asset $asset, array $data = [], ?string $confirmation = null, ?int $version = null): Disposal
{
    return app(RecordDisposal::class)($asset, [
        'disposed_on' => '2026-10-26',
        'reason' => DisposalReason::Retirement->value,
        'decided_by' => 'Conseil d\'administration',
        'decision_reference' => 'PV CA du 24/10/2026',
        ...$data,
    ], $confirmation ?? $asset->number, $version);
}

/**
 * Photographie des lignes d'un plan (toutes versions) pour vérifier qu'aucune ne bouge.
 *
 * @return array<int, array<string, mixed>>
 */
function lineSnapshot(Asset $asset, bool $lockedOnly = true): array
{
    return DepreciationLine::query()
        ->whereIn('depreciation_schedule_id', DepreciationSchedule::query()->where('asset_id', $asset->id)->select('id'))
        ->when($lockedOnly, fn ($query) => $query->where('is_locked', true))
        ->orderBy('id')
        ->get()
        ->map(fn ($line) => $line->getAttributes())
        ->all();
}
