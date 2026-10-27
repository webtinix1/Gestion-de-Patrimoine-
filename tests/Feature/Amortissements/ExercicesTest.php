<?php

use App\Actions\Depreciation\ChangeDepreciationParameters;
use App\Actions\FiscalYears\CloseFiscalYear;
use App\Actions\FiscalYears\CreateFiscalYear;
use App\Actions\FiscalYears\OpenFiscalYear;
use App\Enums\FiscalYearStatus;
use App\Models\Activity;
use App\Models\DepreciationLine;
use App\Models\FiscalYear;
use App\Models\User;
use App\Services\FiscalYearClosing\FiscalYearAttentionPoints;
use App\Support\DefaultRoles;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;

require_once __DIR__.'/helpers.php';

beforeEach(function () {
    $this->accountant = User::factory()->role(DefaultRoles::ACCOUNTANT)->create();
    $this->manager = User::factory()->role(DefaultRoles::MANAGER)->create();
    $this->actingAs($this->accountant);

    fiscalYear(2024, FiscalYearStatus::Closed);
    fiscalYear(2025, FiscalYearStatus::Closed);
    $this->year2026 = fiscalYear(2026, FiscalYearStatus::Open);
    $this->asset = createDepreciableAsset();
});

it('crée un exercice préparé sur l\'année civile (Q5)', function () {
    $year = app(CreateFiscalYear::class)(2027);

    expect($year)
        ->status->toBe(FiscalYearStatus::Prepared)
        ->and($year->starts_on->toDateString())->toBe('2027-01-01')
        ->and($year->ends_on->toDateString())->toBe('2027-12-31')
        ->and(fn () => app(CreateFiscalYear::class)(2027))->toThrow(ValidationException::class);

    app(OpenFiscalYear::class)($year);

    expect($year->fresh()->status)->toBe(FiscalYearStatus::Open);
});

it('n\'autorise la clôture qu\'à partir du dernier jour de l\'exercice (RG-41)', function () {
    expect(fn () => app(CloseFiscalYear::class)($this->year2026, $this->accountant, true, CarbonImmutable::parse('2026-12-30')))
        ->toThrow(ValidationException::class);

    expect($this->year2026->fresh()->status)->toBe(FiscalYearStatus::Open);
});

it('réserve la clôture à l\'administrateur et au comptable (RG-41, RG-83)', function () {
    expect($this->manager->can('close', $this->year2026))->toBeFalse()
        ->and($this->accountant->can('close', $this->year2026))->toBeTrue()
        ->and(User::factory()->admin()->create()->can('close', $this->year2026))->toBeTrue()
        ->and(fn () => app(CloseFiscalYear::class)($this->year2026, $this->manager, true, CarbonImmutable::parse('2026-12-31')))
        ->toThrow(AuthorizationException::class);

    // Même avec le droit « valider » dans la matrice, un autre rôle ne clôture pas.
    $this->manager->givePermissionTo('depreciation.validate');

    expect($this->manager->fresh()->can('close', $this->year2026))->toBeFalse();
});

it('demande de confirmer les points d\'attention, sans les rendre bloquants (RG-42)', function () {
    $points = app(FiscalYearAttentionPoints::class)->for($this->year2026);
    $keys = array_map(fn ($point) => $point->key, $points);

    expect($keys)->toContain('plans', 'disposals', 'inventories')
        ->and(FiscalYearAttentionPoints::needConfirmation($points))->toBeTrue()
        ->and(fn () => app(CloseFiscalYear::class)($this->year2026, $this->accountant, false, CarbonImmutable::parse('2026-12-31')))
        ->toThrow(ValidationException::class);

    expect($this->year2026->fresh()->status)->toBe(FiscalYearStatus::Open);
});

it('fige les lignes, valide les plans, ouvre l\'exercice suivant et trace la clôture (RG-43, RG-38)', function () {
    app(CloseFiscalYear::class)($this->year2026, $this->accountant, true, CarbonImmutable::parse('2026-12-31'));

    $closed = $this->year2026->fresh();
    $schedule = currentSchedule($this->asset);

    expect($closed)
        ->status->toBe(FiscalYearStatus::Closed)
        ->closed_by->toBe($this->accountant->id)
        ->and($closed->closing_notes['acknowledged'])->toBeTrue()
        ->and($schedule->lines->firstWhere('fiscal_year', 2026)->is_locked)->toBeTrue()
        ->and($schedule->lines->firstWhere('fiscal_year', 2027)->is_locked)->toBeFalse()
        ->and($schedule->validated_by)->toBe($this->accountant->id)
        ->and(FiscalYear::query()->where('year', 2027)->sole()->status)->toBe(FiscalYearStatus::Open)
        ->and(Activity::query()->where('event', 'fiscal_year_closed')->sole()->description)->toBe('Clôture de l\'exercice 2026');
});

it('ouvre l\'exercice suivant s\'il était seulement préparé (RG-43)', function () {
    app(CreateFiscalYear::class)(2027);

    app(CloseFiscalYear::class)($this->year2026, $this->accountant, true, CarbonImmutable::parse('2027-01-05'));

    expect(FiscalYear::query()->where('year', 2027)->sole()->status)->toBe(FiscalYearStatus::Open)
        ->and(FiscalYear::query()->count())->toBe(4);
});

it('rend la clôture irréversible (RG-43)', function () {
    app(CloseFiscalYear::class)($this->year2026, $this->accountant, true, CarbonImmutable::parse('2026-12-31'));
    $closed = $this->year2026->fresh();

    expect(fn () => $closed->update(['status' => FiscalYearStatus::Open]))->toThrow(LogicException::class)
        ->and(fn () => $closed->delete())->toThrow(LogicException::class)
        ->and(fn () => app(CloseFiscalYear::class)($closed, $this->accountant, true, CarbonImmutable::parse('2027-01-01')))->toThrow(ValidationException::class)
        ->and($this->accountant->can('update', $closed))->toBeFalse()
        ->and($this->accountant->can('delete', $closed))->toBeFalse();
});

it('clôture les exercices dans l\'ordre', function () {
    $year2027 = fiscalYear(2027, FiscalYearStatus::Open);

    expect(fn () => app(CloseFiscalYear::class)($year2027, $this->accountant, true, CarbonImmutable::parse('2027-12-31')))
        ->toThrow(ValidationException::class);
});

it('ne recalcule plus un exercice clôturé après sa clôture (RG-36)', function () {
    app(CloseFiscalYear::class)($this->year2026, $this->accountant, true, CarbonImmutable::parse('2026-12-31'));
    $locked = DepreciationLine::query()->where('is_locked', true)->get()->map->getAttributes()->all();

    app(ChangeDepreciationParameters::class)($this->asset, ['duration_years' => 4], 'Durée d\'usage constatée plus longue');

    $schedule = currentSchedule($this->asset);

    // Version validée par la clôture conservée ; la nouvelle version reprend 2024 à 2026 à l'identique.
    expect(DepreciationLine::query()->whereIn('id', array_column($locked, 'id'))->get()->map->getAttributes()->all())->toBe($locked)
        ->and($schedule->version)->toBe(2)
        ->and($schedule->recalculated_from_year)->toBe(2027)
        // 38 500 restants au 01/01/2027 répartis sur 360 + 77 jours : 31 716 puis 6 784.
        ->and($schedule->lines->pluck('amount', 'fiscal_year')->all())->toBe([2024 => 141500, 2025 => 180000, 2026 => 180000, 2027 => 31716, 2028 => 6784]);
});
