<?php

use App\Actions\Assets\UpdateAsset;
use App\Actions\Depreciation\ChangeDepreciationParameters;
use App\Actions\Depreciation\ValidateDepreciationSchedule;
use App\Actions\FiscalYears\CloseFiscalYear;
use App\Actions\FiscalYears\CreateFiscalYear;
use App\Enums\Depreciability;
use App\Enums\DepreciationMethod;
use App\Enums\FiscalYearStatus;
use App\Models\Activity;
use App\Models\Asset;
use App\Models\AssetCategory;
use App\Models\DepreciationLine;
use App\Models\User;
use App\Services\DepreciationCalculator;
use App\Services\FiscalYearCalendar;
use App\Services\FiscalYearClosing\FiscalYearAttentionPoints;
use App\Services\Settings;
use App\Support\DefaultRoles;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;

require_once __DIR__.'/helpers.php';

/**
 * Non-régression issue de la revue indépendante du lot 7 (scénarios P1 à P4 et corrections).
 */
beforeEach(function () {
    $this->admin = User::factory()->admin()->create();
    $this->actingAs($this->admin);
    fiscalYear(2023, FiscalYearStatus::Closed);
    fiscalYear(2024, FiscalYearStatus::Closed);
    fiscalYear(2025, FiscalYearStatus::Closed);
    $this->year2026 = fiscalYear(2026, FiscalYearStatus::Open);
});

/**
 * @return list<array{0: int, 1: int, 2: bool}>
 */
function lockedState(Asset $asset): array
{
    return currentSchedule($asset)->lines->map(fn (DepreciationLine $line) => [$line->fiscal_year, $line->amount, $line->is_locked])->values()->all();
}

it('P1 : refuse un exercice créé avant les exercices existants et garde les lignes clôturées figées (RG-36)', function () {
    expect(fn () => app(CreateFiscalYear::class)(2022))->toThrow(ValidationException::class);

    // Même si un exercice antérieur existe déjà (donnée ancienne), il ne rouvre pas les années closes.
    fiscalYear(2022, FiscalYearStatus::Open);
    $asset = createDepreciableAsset(['acquired_on' => '2023-06-01', 'in_service_on' => '2023-06-01']);
    $before = lockedState($asset);

    expect((new FiscalYearCalendar)->firstRecalculableYear())->toBe(2026)
        ->and(array_slice(array_column($before, 2), 0, 3))->toBe([true, true, true]);

    app(ChangeDepreciationParameters::class)($asset, ['duration_years' => 5], 'Essai revue');

    expect(array_slice(lockedState($asset), 0, 3))->toBe(array_slice($before, 0, 3));
});

it('P2 : un exercice préparé antérieur ne sert pas d\'exercice d\'effet (A6 conservé)', function () {
    $asset = createDepreciableAsset();
    fiscalYear(2021, FiscalYearStatus::Prepared);

    app(ChangeDepreciationParameters::class)($asset, ['duration_years' => 4], 'Essai revue');

    expect(scheduleLines($asset))->toBe(depreciationCase('A6')['attendu'])
        ->and(currentSchedule($asset)->recalculated_from_year)->toBe(2026);
});

it('P3 : refuse une mise en service repoussée après des exercices figés portant une dotation', function () {
    $asset = createDepreciableAsset();

    try {
        app(ChangeDepreciationParameters::class)($asset, ['in_service_on' => '2026-01-01'], 'Essai revue');
        $this->fail('Mise en service repoussée acceptée.');
    } catch (ValidationException $exception) {
        expect($exception->errors())->toHaveKey('in_service_on')
            ->and($exception->errors()['in_service_on'][0])->toContain('2024');
    }

    // Avancer la mise en service dans un exercice clôturé sans ligne est aussi refusé.
    expect(fn () => app(ChangeDepreciationParameters::class)($asset, ['in_service_on' => '2023-06-01'], 'Essai revue'))
        ->toThrow(ValidationException::class);

    // Déplacer la date à l'intérieur du même exercice figé reste possible.
    app(ChangeDepreciationParameters::class)($asset, ['in_service_on' => '2024-04-01'], 'Date réelle');

    expect(currentSchedule($asset)->lines->firstWhere('fiscal_year', 2024)->amount)->toBe(141500)
        ->and(currentSchedule($asset)->lines->sum('amount'))->toBe(540000);
});

it('P4 : la méthode et le coefficient restent ceux de la fiche quand le dégressif est désactivé après coup (RG-35)', function () {
    app(Settings::class)->set(Settings::DECLINING_ENABLED, true);
    $category = AssetCategory::factory()->create(['prefix' => 'VEH', 'depreciable' => Depreciability::Yes, 'default_duration_years' => 5, 'default_method' => DepreciationMethod::Declining]);
    $engaged = createDepreciableAsset(['acquisition_cost' => 1000000, 'acquired_on' => '2024-01-01', 'in_service_on' => '2024-01-01'], $category);
    $free = createDepreciableAsset(['acquisition_cost' => 1000000, 'acquired_on' => '2026-01-01', 'in_service_on' => '2026-01-01'], $category);

    expect(DepreciationCalculator::parseScaled((string) $free->fresh()->getAttribute('declining_coefficient')))->toBe(20000);

    app(Settings::class)->set(Settings::DECLINING_ENABLED, false);
    app(Settings::class)->set(Settings::DECLINING_COEFFICIENT_5_6, '2.5');

    app(UpdateAsset::class)($free, ['designation' => 'Nouveau nom']);
    app(UpdateAsset::class)($engaged, ['designation' => 'Nouveau nom']);

    expect($free->fresh()->depreciation_method)->toBe(DepreciationMethod::Declining)
        ->and(currentSchedule($free)->method)->toBe(DepreciationMethod::Declining)
        ->and(currentSchedule($free)->declining_coefficient)->toBe('2.0000')
        ->and($engaged->fresh()->designation)->toBe('Nouveau nom')
        ->and(scheduleLines($engaged, 'mois'))->toBe(depreciationCase('A7')['attendu']);

    // Un nouveau bien prend le linéaire.
    expect(createDepreciableAsset(['acquisition_cost' => 1000000, 'acquired_on' => '2026-02-01', 'in_service_on' => '2026-02-01'], $category)->depreciation_method)
        ->toBe(DepreciationMethod::Linear);
});

it('enregistre sans débordement les plans dont le taux de ligne n\'a pas de sens (decimal(7,4), MariaDB strict)', function () {
    app(Settings::class)->set(Settings::DECLINING_ENABLED, true);
    $category = AssetCategory::factory()->create(['prefix' => 'VEH', 'depreciable' => Depreciability::Yes, 'default_duration_years' => 5, 'default_method' => DepreciationMethod::Declining]);
    $declining = createDepreciableAsset(['acquisition_cost' => 1000000, 'acquired_on' => '2026-02-01', 'in_service_on' => '2026-02-01'], $category);

    expect(currentSchedule($declining)->lines->last()->months)->toBe(1)
        ->and(currentSchedule($declining)->lines->last()->rate)->toBeNull()
        ->and(currentSchedule($declining)->lines->sum('amount'))->toBe(1000000);

    $linear = createDepreciableAsset(['acquired_on' => '2024-01-20', 'in_service_on' => '2024-01-20']);
    app(ChangeDepreciationParameters::class)($linear, ['duration_years' => 2], 'Réduction de durée');

    expect(currentSchedule($linear)->lines->firstWhere('fiscal_year', 2026)->rate)->toBeNull()
        ->and(currentSchedule($linear)->lines->sum('amount'))->toBe(540000);
});

it('réserve en dur la modification des paramètres et la validation à l\'administrateur et au comptable (RG-83)', function () {
    $asset = createDepreciableAsset();
    $manager = User::factory()->role(DefaultRoles::MANAGER)->create();
    $manager->givePermissionTo(['depreciation.update', 'depreciation.validate']);
    $this->actingAs($manager);

    expect(fn () => app(ChangeDepreciationParameters::class)($asset, ['duration_years' => 4], 'Essai'))
        ->toThrow(AuthorizationException::class)
        ->and(fn () => app(ValidateDepreciationSchedule::class)(currentSchedule($asset), $manager))
        ->toThrow(AuthorizationException::class)
        ->and($asset->fresh()->duration_years)->toBe(3)
        ->and(currentSchedule($asset)->isValidated())->toBeFalse();
});

it('rattache les erreurs de recalcul au bon champ avec un message métier', function () {
    $asset = createDepreciableAsset();

    try {
        app(ChangeDepreciationParameters::class)($asset, ['duration_years' => 1], 'Essai');
        $this->fail('Durée impossible acceptée.');
    } catch (ValidationException $exception) {
        expect(array_keys($exception->errors()))->toBe(['data.duration_years'])
            ->and($exception->errors()['data.duration_years'][0])->toContain('durée');
    }

    try {
        app(ChangeDepreciationParameters::class)($asset, ['acquisition_cost' => 300000], 'Essai');
        $this->fail('Base impossible acceptée.');
    } catch (ValidationException $exception) {
        expect(array_keys($exception->errors()))->toBe(['data.acquisition_cost']);
    }
});

it('affiche les anomalies de recalcul comme points d\'attention avant la clôture (RG-42)', function () {
    $asset = createDepreciableAsset();
    // Valeur incohérente posée hors écran : le plan ne peut plus être recalculé.
    Asset::query()->whereKey($asset->id)->update(['acquisition_cost' => 300000]);

    $points = app(FiscalYearAttentionPoints::class)->for($this->year2026);
    $anomaly = collect($points)->firstWhere('key', 'plan_anomalies');

    expect($anomaly)->not->toBeNull()
        ->and($anomaly->label)->toContain($asset->number)
        ->and($anomaly->needsConfirmation())->toBeTrue();

    app(CloseFiscalYear::class)($this->year2026, $this->admin, true, CarbonImmutable::parse('2026-12-31'));

    expect($this->year2026->fresh()->closing_notes['attention_points'])->toContain($anomaly->toArray());
});

it('cite au journal de la clôture les biens dont le plan est validé', function () {
    $asset = createDepreciableAsset();

    app(CloseFiscalYear::class)($this->year2026, $this->admin, true, CarbonImmutable::parse('2026-12-31'));

    expect(Activity::query()->where('event', 'fiscal_year_closed')->sole()->properties->get('validated_assets'))->toBe([$asset->number]);
});
