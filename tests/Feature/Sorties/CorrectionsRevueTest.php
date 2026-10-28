<?php

use App\Actions\Disposals\RecordDisposal;
use App\Filament\Resources\Disposals\Pages\CreateDisposal;
use App\Models\Asset;
use App\Models\Disposal;
use App\Models\User;
use App\Support\DefaultRoles;
use Filament\Facades\Filament;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Livewire\Exceptions\MethodNotFoundException;
use Tests\Support\Fakes\FakeInventoryLine;
use Tests\Support\Fakes\FakeInventoryResolution;
use Tests\Support\Fakes\FakeResolveInventoryLine;
use Tests\Support\Fakes\RecordDisposalWithFakeInventory;

use function Pest\Livewire\livewire;

require_once __DIR__.'/helpers.php';
require_once __DIR__.'/../../Support/Fakes/InventoryFakes.php';

beforeEach(function () {
    Carbon::setTestNow('2026-10-28 10:00:00');
    disposalFiscalYears();
    FakeInventoryLine::$lines = [];
    FakeResolveInventoryLine::$calls = [];
});

afterEach(fn () => Carbon::setTestNow());

it('refuse la sortie d\'un bien devenu non amortissable dont le plan est engagé, sans rien figer (I-1)', function () {
    $asset = a5Asset();
    $lines = lineSnapshot($asset, lockedOnly: false);
    // Exercices 2022 à 2025 figés, puis le bien est rendu non amortissable hors écran.
    Asset::query()->whereKey($asset->id)->update(['is_depreciable' => false]);

    expect(fn () => recordDisposal($asset->fresh()))->toThrow(ValidationException::class, 'par le comptable')
        ->and(Disposal::query()->count())->toBe(0)
        ->and($asset->fresh()->isDisposed())->toBeFalse()
        ->and(lineSnapshot($asset, lockedOnly: false))->toBe($lines);
});

it('ne laisse pas le navigateur mémoriser une version de fiche (M-1, RG-86)', function () {
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    $asset = a5Asset();
    $this->actingAs(User::factory()->role(DefaultRoles::MANAGER)->create());

    expect(fn () => livewire(CreateDisposal::class)->call('rememberAssetVersion', $asset->id))->toThrow(MethodNotFoundException::class);

    livewire(CreateDisposal::class)
        ->set('data.asset_id', $asset->id)
        ->assertSet('assetVersions', [$asset->id => $asset->fresh()->lock_version]);
});

it('n\'accepte que des dates réelles au format AAAA-MM-JJ (M-2)', function (string $date) {
    $asset = createDepreciableAsset();

    try {
        recordDisposal($asset, ['disposed_on' => $date]);
        $this->fail('Date acceptée : '.$date);
    } catch (ValidationException $exception) {
        expect($exception->errors())->toHaveKey('disposed_on');
    }

    expect($asset->fresh()->isDisposed())->toBeFalse();
})->with(['2026-02-30', 'yesterday', '26/10/2026', '2026-10-26 extra', 'now']);

it('appelle le résolveur d\'inventaire avec le modèle de la ligne, le motif « Disposal » et la sortie (M-4)', function () {
    $asset = createDepreciableAsset();
    $line = FakeInventoryLine::make(42, $asset->id);

    $disposal = app(RecordDisposalWithFakeInventory::class)($asset, ['disposed_on' => '2026-10-26', 'reason' => 'retirement', 'decided_by' => 'Conseil'], $asset->number, inventoryLineId: 42);

    expect(FakeResolveInventoryLine::$calls)->toHaveCount(1)
        ->and(FakeResolveInventoryLine::$calls[0][0])->toBe($line)
        ->and(FakeResolveInventoryLine::$calls[0][1])->toBe(FakeInventoryResolution::Disposal)
        ->and(FakeResolveInventoryLine::$calls[0][2]->is($disposal))->toBeTrue();
});

it('ignore, avec une trace, une ligne d\'inventaire d\'un autre bien ou introuvable (M-4)', function (bool $exists) {
    Log::spy();
    $asset = createDepreciableAsset();

    if ($exists) {
        FakeInventoryLine::make(7, $asset->id + 1000);
    }

    app(RecordDisposalWithFakeInventory::class)($asset, ['disposed_on' => '2026-10-26', 'reason' => 'retirement', 'decided_by' => 'Conseil'], $asset->number, inventoryLineId: 7);

    expect(FakeResolveInventoryLine::$calls)->toBe([])
        ->and($asset->fresh()->isDisposed())->toBeTrue();
    Log::shouldHaveReceived('warning')->once();
})->with(['autre bien' => true, 'introuvable' => false]);

it('ignore le paramètre d\'inventaire tant que le lot 9 n\'est pas là', function () {
    $asset = createDepreciableAsset();

    app(RecordDisposal::class)($asset, ['disposed_on' => '2026-10-26', 'reason' => 'retirement', 'decided_by' => 'Conseil'], $asset->number, inventoryLineId: 42);

    expect($asset->fresh()->isDisposed())->toBeTrue();
});

it('interdit toute modification d\'une fiche sortie, hors date de mise à jour (S-1)', function (array $change) {
    $asset = createDepreciableAsset();
    recordDisposal($asset);

    expect(fn () => $asset->fresh()->forceFill($change)->save())->toThrow(LogicException::class, 'lecture seule');
})->with([
    'désignation' => [['designation' => 'Autre']],
    'observations' => [['notes' => 'Modifiée']],
    'affectation' => [['room_id' => null, 'holder_id' => null, 'site_id' => 999]],
    'prix' => [['acquisition_cost' => 1]],
]);

it('laisse seulement toucher la date de mise à jour d\'une fiche sortie', function () {
    $asset = createDepreciableAsset();
    recordDisposal($asset);

    expect($asset->fresh()->touch())->toBeTrue();
});
