<?php

use App\Actions\Assets\UpdateAsset;
use App\Actions\Depreciation\ChangeDepreciationParameters;
use App\Actions\Depreciation\SyncDepreciationSchedule;
use App\Actions\Depreciation\SyncOutcome;
use App\Actions\Depreciation\ValidateDepreciationSchedule;
use App\Enums\Depreciability;
use App\Enums\DepreciationMethod;
use App\Enums\FiscalYearStatus;
use App\Models\Activity;
use App\Models\Asset;
use App\Models\AssetCategory;
use App\Models\DepreciationLine;
use App\Models\DepreciationParameterChange;
use App\Models\DepreciationSchedule;
use App\Models\User;
use App\Services\Settings;
use App\Support\DefaultRoles;
use Illuminate\Validation\ValidationException;

require_once __DIR__.'/helpers.php';

beforeEach(function () {
    $this->accountant = User::factory()->role(DefaultRoles::ACCOUNTANT)->create();
    $this->actingAs(User::factory()->admin()->create());
});

it('génère le plan à la création d\'un bien amortissable (A2)', function () {
    $asset = createDepreciableAsset();
    $schedule = currentSchedule($asset);

    expect($schedule)->not->toBeNull()
        ->and($schedule->version)->toBe(1)
        ->and($schedule->base_amount)->toBe(540000)
        ->and($schedule->rate)->toBe('33.3333')
        ->and($schedule->ends_on->toDateString())->toBe('2027-03-17')
        ->and(scheduleLines($asset))->toBe(depreciationCase('A2')['attendu']);
});

it('ne génère aucun plan sous le seuil d\'immobilisation (A8, RG-30)', function () {
    $case = depreciationCase('A8');
    $category = AssetCategory::factory()->create(['prefix' => 'BUR', 'depreciable' => Depreciability::ThresholdBased, 'default_duration_years' => 5]);

    $asset = createDepreciableAsset(['acquisition_cost' => $case['valeur_origine'], 'in_service_on' => $case['mise_en_service'], 'acquired_on' => $case['mise_en_service']], $category);

    expect($asset->is_depreciable)->toBeFalse()
        ->and(DepreciationSchedule::query()->where('asset_id', $asset->id)->exists())->toBeFalse()
        ->and(scheduleLines($asset))->toBe($case['attendu']);
});

it('recalcule un plan jamais engagé quand la fiche change', function () {
    $asset = createDepreciableAsset();

    app(UpdateAsset::class)($asset, ['acquisition_cost' => 600000, 'in_service_on' => '2024-01-01', 'duration_years' => 3]);

    expect(scheduleLines($asset))->toBe(depreciationCase('A1')['attendu'])
        ->and(DepreciationSchedule::query()->where('asset_id', $asset->id)->count())->toBe(1);
});

it('refuse depuis la fiche la modification d\'un plan engagé et ne change rien (RG-37)', function () {
    fiscalYear(2024, FiscalYearStatus::Closed);
    fiscalYear(2025, FiscalYearStatus::Closed);
    fiscalYear(2026, FiscalYearStatus::Open);
    $asset = createDepreciableAsset();
    $before = scheduleLines($asset);

    expect(fn () => app(UpdateAsset::class)($asset, ['duration_years' => 4]))->toThrow(ValidationException::class);

    expect($asset->fresh()->duration_years)->toBe(3)
        ->and(scheduleLines($asset))->toBe($before);
});

it('fige les exercices clôturés et antérieurs dès la génération (RG-36)', function () {
    fiscalYear(2024, FiscalYearStatus::Closed);
    fiscalYear(2025, FiscalYearStatus::Closed);
    fiscalYear(2026, FiscalYearStatus::Open);

    $asset = createDepreciableAsset();
    $locked = currentSchedule($asset)->lines->mapWithKeys(fn (DepreciationLine $line) => [$line->fiscal_year => $line->is_locked])->all();

    expect($locked)->toBe([2024 => true, 2025 => true, 2026 => false, 2027 => false]);
});

it('applique un changement de durée de façon prospective, avec motif et historique (A6, RG-37)', function () {
    $case = depreciationCase('A6');
    fiscalYear(2024, FiscalYearStatus::Closed);
    fiscalYear(2025, FiscalYearStatus::Closed);
    fiscalYear(2026, FiscalYearStatus::Open);
    $asset = createDepreciableAsset();
    $lockedBefore = currentSchedule($asset)->lines->where('is_locked', true)->map->getAttributes()->values()->all();

    app(ChangeDepreciationParameters::class)($asset, ['duration_years' => $case['modification']['nouvelle_duree_ans']], 'Durée d\'usage constatée plus longue');

    expect(scheduleLines($asset))->toBe($case['attendu'])
        ->and(currentSchedule($asset)->lines->where('is_locked', true)->map->getAttributes()->values()->all())->toBe($lockedBefore)
        ->and(currentSchedule($asset)->recalculated_from_year)->toBe(2026);

    $change = DepreciationParameterChange::query()->sole();

    expect($change)
        ->parameter->toBe('duration_years')
        ->old_value->toBe('3')
        ->new_value->toBe('4')
        ->reason->toBe('Durée d\'usage constatée plus longue')
        ->effective_year->toBe(2026)
        ->changed_by->toBe(auth()->id());

    $activity = Activity::query()->where('log_name', 'amortissements')->where('event', 'depreciation_parameters_changed')->sole();

    expect($activity->properties->get('old'))->toBe(['duration_years' => '3'])
        ->and($activity->properties->get('attributes'))->toBe(['duration_years' => '4'])
        ->and($activity->properties->get('reason'))->toBe('Durée d\'usage constatée plus longue');
});

it('exige un motif pour modifier un paramètre (RG-37)', function () {
    $asset = createDepreciableAsset();

    expect(fn () => app(ChangeDepreciationParameters::class)($asset, ['duration_years' => 4], '  '))
        ->toThrow(ValidationException::class);

    expect($asset->fresh()->duration_years)->toBe(3)
        ->and(DepreciationParameterChange::query()->count())->toBe(0);
});

it('refuse un changement qui obligerait à modifier un exercice clôturé (RG-34, RG-36)', function () {
    fiscalYear(2024, FiscalYearStatus::Closed);
    fiscalYear(2025, FiscalYearStatus::Closed);
    fiscalYear(2026, FiscalYearStatus::Open);
    $asset = createDepreciableAsset();

    expect(fn () => app(ChangeDepreciationParameters::class)($asset, ['acquisition_cost' => 300000], 'Erreur de facture'))
        ->toThrow(ValidationException::class);

    expect($asset->fresh()->acquisition_cost)->toBe(540000)
        ->and(scheduleLines($asset))->toBe(depreciationCase('A2')['attendu']);
});

it('ne modifie jamais une ligne figée, même par un recalcul forcé (RG-36)', function () {
    fiscalYear(2024, FiscalYearStatus::Closed);
    fiscalYear(2025, FiscalYearStatus::Closed);
    fiscalYear(2026, FiscalYearStatus::Open);
    $asset = createDepreciableAsset();
    $locked = currentSchedule($asset)->lines->where('is_locked', true)->map->getAttributes()->values()->all();

    // Valeur d'origine changée directement en base, sans passer par l'écran : le recalcul ne touche qu'aux exercices ouverts.
    Asset::query()->whereKey($asset->id)->update(['acquisition_cost' => 600000]);
    app(SyncDepreciationSchedule::class)($asset->fresh());

    $after = currentSchedule($asset);

    expect($after->lines->where('is_locked', true)->map->getAttributes()->values()->all())->toBe($locked)
        ->and($after->lines->sum('amount'))->toBe(600000);

    $line = $after->lines->firstWhere('fiscal_year', 2024);

    expect(fn () => $line->update(['amount' => 1]))->toThrow(LogicException::class)
        ->and(fn () => $line->delete())->toThrow(LogicException::class)
        ->and($line->fresh()->amount)->toBe(141500);
});

it('conserve chaque version validée et crée une nouvelle version ensuite (RG-38)', function () {
    fiscalYear(2024, FiscalYearStatus::Closed);
    fiscalYear(2025, FiscalYearStatus::Closed);
    fiscalYear(2026, FiscalYearStatus::Open);
    $asset = createDepreciableAsset();
    $first = currentSchedule($asset);

    app(ValidateDepreciationSchedule::class)($first, $this->accountant);

    expect($first->fresh()->validated_by)->toBe($this->accountant->id)
        ->and(Activity::query()->where('event', 'depreciation_schedule_validated')->sole()->subject_id)->toBe($asset->id)
        ->and(fn () => app(ValidateDepreciationSchedule::class)($first->fresh(), $this->accountant))->toThrow(ValidationException::class);

    $firstLines = $first->lines()->get()->map->getAttributes()->all();

    app(ChangeDepreciationParameters::class)($asset, ['duration_years' => 4], 'Durée d\'usage constatée plus longue');

    $second = currentSchedule($asset);

    expect($second->version)->toBe(2)
        ->and($second->isValidated())->toBeFalse()
        ->and($first->fresh()->is_current)->toBeFalse()
        ->and($first->lines()->get()->map->getAttributes()->all())->toBe($firstLines)
        ->and(scheduleLines($asset))->toBe(depreciationCase('A6')['attendu'])
        ->and(DepreciationSchedule::query()->where('asset_id', $asset->id)->where('is_current', true)->count())->toBe(1);

    expect(fn () => $first->fresh()->update(['duration_years' => 9]))->toThrow(LogicException::class);
});

it('est idempotent : un second recalcul ne change rien', function () {
    fiscalYear(2025, FiscalYearStatus::Closed);
    fiscalYear(2026, FiscalYearStatus::Open);
    $asset = createDepreciableAsset();
    $snapshot = currentSchedule($asset)->lines->map->getAttributes()->all();

    expect(app(SyncDepreciationSchedule::class)($asset))->toBe(SyncOutcome::Unchanged)
        ->and(currentSchedule($asset)->lines->map->getAttributes()->all())->toBe($snapshot);
});

it('arrête la dotation à la date de sortie incluse (A5, RG-39)', function () {
    $case = depreciationCase('A5');
    $category = AssetCategory::factory()->create(['prefix' => 'ELE', 'depreciable' => Depreciability::Yes, 'default_duration_years' => 8]);
    $asset = createDepreciableAsset(['acquisition_cost' => 4800000, 'acquired_on' => '2022-06-15', 'in_service_on' => '2022-06-15'], $category);

    // L'action de sortie arrive au lot 10 : la date de sortie est posée directement.
    Asset::query()->whereKey($asset->id)->update(['disposed_at' => $case['date_sortie']]);
    app(SyncDepreciationSchedule::class)($asset->fresh());

    expect(scheduleLines($asset))->toBe($case['attendu']);
});

it('garde le linéaire tant que le dégressif est désactivé (RG-35)', function () {
    $category = AssetCategory::factory()->create(['prefix' => 'VEH', 'depreciable' => Depreciability::Yes, 'default_duration_years' => 5, 'default_method' => DepreciationMethod::Declining]);
    $asset = createDepreciableAsset(['acquisition_cost' => 1000000, 'acquired_on' => '2024-01-01', 'in_service_on' => '2024-01-01'], $category);

    expect($asset->depreciation_method)->toBe(DepreciationMethod::Linear)
        ->and(fn () => app(ChangeDepreciationParameters::class)($asset, ['depreciation_method' => 'declining'], 'Essai'))->toThrow(ValidationException::class);
});

it('applique le dégressif quand le paramètre est activé (A7, RG-35)', function () {
    $case = depreciationCase('A7');
    app(Settings::class)->set(Settings::DECLINING_ENABLED, true);
    $category = AssetCategory::factory()->create(['prefix' => 'VEH', 'depreciable' => Depreciability::Yes, 'default_duration_years' => 5, 'default_method' => DepreciationMethod::Declining]);

    $asset = createDepreciableAsset(['acquisition_cost' => $case['valeur_origine'], 'acquired_on' => $case['mise_en_service'], 'in_service_on' => $case['mise_en_service']], $category);

    expect($asset->depreciation_method)->toBe(DepreciationMethod::Declining)
        ->and(currentSchedule($asset)->declining_coefficient)->toBe('2.0000')
        ->and(scheduleLines($asset, 'mois'))->toBe($case['attendu']);
});
