<?php

use App\Actions\Assets\CreateAsset;
use App\Enums\AssetStatus;
use App\Models\Asset;
use App\Models\AssetCategory;
use App\Models\AssetCondition;
use App\Models\Department;
use App\Models\Site;
use App\Services\AssetNumberGenerator;
use App\Services\Settings;
use Illuminate\Database\UniqueConstraintViolationException;

function newAsset(AssetCategory $category, array $overrides = []): Asset
{
    return app(CreateAsset::class)([
        'designation' => 'Chaise',
        'asset_category_id' => $category->id,
        'acquired_on' => '2026-10-01',
        'site_id' => Site::factory()->create()->id,
        'department_id' => Department::factory()->create()->id,
        'asset_condition_id' => AssetCondition::factory()->create()->id,
        'status' => AssetStatus::InService->value,
        ...$overrides,
    ]);
}

beforeEach(function () {
    $this->category = AssetCategory::factory()->create(['prefix' => 'INF', 'depreciable' => 'no']);
});

it('attribue PAT-000001 puis PAT-000002 par défaut (RG-01, RG-02)', function () {
    expect(newAsset($this->category)->number)->toBe('PAT-000001')
        ->and(newAsset($this->category)->number)->toBe('PAT-000002');
});

it('utilise un compteur annuel avec le format « avec l\'année »', function () {
    app(Settings::class)->set(Settings::NUMBER_FORMAT, Settings::FORMAT_YEARLY);
    $this->travelTo('2026-12-31 10:00');

    expect(newAsset($this->category)->number)->toBe('PAT-2026-000001')
        ->and(newAsset($this->category)->number)->toBe('PAT-2026-000002');

    $this->travelTo('2027-01-01 08:00');

    expect(newAsset($this->category)->number)->toBe('PAT-2027-000001');
});

it('utilise un compteur par préfixe de catégorie avec le format « par catégorie »', function () {
    app(Settings::class)->set(Settings::NUMBER_FORMAT, Settings::FORMAT_CATEGORY);
    $furniture = AssetCategory::factory()->create(['prefix' => 'MOB', 'depreciable' => 'no']);

    expect(newAsset($this->category)->number)->toBe('INF-000001')
        ->and(newAsset($furniture)->number)->toBe('MOB-000001')
        ->and(newAsset($this->category)->number)->toBe('INF-000002');
});

it('n\'applique un nouveau format qu\'aux nouveaux biens', function () {
    $old = newAsset($this->category);
    app(Settings::class)->set(Settings::NUMBER_FORMAT, Settings::FORMAT_CATEGORY);

    newAsset($this->category);

    expect($old->refresh()->number)->toBe('PAT-000001');
});

it('refuse toute modification du numéro (RG-04)', function () {
    $asset = newAsset($this->category);
    $asset->forceFill(['number' => 'PAT-999999']);

    expect(fn () => $asset->save())->toThrow(LogicException::class);
    expect($asset->refresh()->number)->toBe('PAT-000001');
});

it('ne réattribue jamais un numéro, même après la sortie d\'un bien (RG-04)', function () {
    $first = newAsset($this->category);
    $first->forceFill(['disposed_at' => now(), 'status' => AssetStatus::Retired])->save();

    expect(newAsset($this->category)->number)->toBe('PAT-000002');
});

it('ne permet pas de supprimer un bien (RG-50)', function () {
    $asset = newAsset($this->category);

    expect(fn () => $asset->delete())->toThrow(LogicException::class);
    expect(Asset::query()->count())->toBe(1);
});

it('affiche le prochain numéro sans le réserver', function () {
    $generator = app(AssetNumberGenerator::class);

    expect($generator->preview($this->category))->toBe('PAT-000001')
        ->and($generator->preview($this->category))->toBe('PAT-000001')
        ->and(newAsset($this->category)->number)->toBe('PAT-000001');
});

it('aligne le compteur sur les numéros repris de l\'existant', function () {
    app(AssetNumberGenerator::class)->syncWith('global', 1248);

    expect(newAsset($this->category)->number)->toBe('PAT-001249');
});

it('garantit l\'unicité en base par un index unique', function () {
    $asset = newAsset($this->category);

    expect(fn () => Asset::factory()->create(['number' => $asset->number]))
        ->toThrow(UniqueConstraintViolationException::class);
});
