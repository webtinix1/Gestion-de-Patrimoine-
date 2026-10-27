<?php

use App\Enums\DepreciationMethod;
use App\Services\Depreciation\DepreciationInput;
use App\Services\Depreciation\DepreciationLineResult;
use App\Services\Depreciation\DepreciationPlan;
use App\Services\Depreciation\FixedLine;
use App\Services\Depreciation\ImpossibleDepreciationPlan;
use App\Services\Depreciation\InvalidDepreciationInput;
use App\Services\DepreciationCalculator;
use Carbon\CarbonImmutable;

/**
 * Non-régression issue de la revue indépendante du lot 7 (cas R1 à R7).
 */
function revueInput(array $overrides = []): DepreciationInput
{
    return new DepreciationInput(...[
        'method' => DepreciationMethod::Linear,
        'originalValue' => 540000,
        'residualValue' => 0,
        'durationYears' => 3,
        'inServiceOn' => CarbonImmutable::parse('2024-03-18'),
        ...$overrides,
    ]);
}

function lastLine(DepreciationPlan $plan): DepreciationLineResult
{
    return $plan->lines[count($plan->lines) - 1];
}

/**
 * Taux qui tiennent dans decimal(7,4) : au plus 999,9999 %.
 */
function ratesFitColumn(DepreciationPlan $plan): bool
{
    foreach ([$plan->rate, ...array_map(fn (DepreciationLineResult $line): ?string => $line->rate, $plan->lines)] as $rate) {
        if ($rate !== null && DepreciationCalculator::parseScaled($rate) > 9_999_999) {
            return false;
        }
    }

    return true;
}

it('R1 : pas de taux de ligne hors colonne en fin de dégressif', function () {
    $plan = (new DepreciationCalculator)->calculate(revueInput([
        'method' => DepreciationMethod::Declining, 'originalValue' => 1000000, 'durationYears' => 5,
        'inServiceOn' => CarbonImmutable::parse('2024-02-01'), 'decliningCoefficient' => '2',
    ]));

    expect(lastLine($plan)->months)->toBe(1)
        ->and(lastLine($plan)->rate)->toBeNull()
        ->and($plan->rate)->toBe('40.0000')
        ->and($plan->totalAmount())->toBe(1000000)
        ->and(ratesFitColumn($plan))->toBeTrue();
});

it('R2 : pas de taux de ligne hors colonne après une réduction de durée (RG-37)', function () {
    $calculator = new DepreciationCalculator;
    $initial = $calculator->calculate(revueInput(['inServiceOn' => CarbonImmutable::parse('2024-01-20')]));
    $fixed = [];

    foreach ($initial->lines as $line) {
        if ($line->year < 2026) {
            $fixed[$line->year] = new FixedLine($line->year, $line->amount, days: $line->days);
        }
    }

    $plan = $calculator->calculate(revueInput([
        'inServiceOn' => CarbonImmutable::parse('2024-01-20'), 'durationYears' => 2, 'fixedLines' => $fixed, 'recalculatedFromYear' => 2026,
    ]));

    expect(lastLine($plan)->year)->toBe(2026)
        ->and(lastLine($plan)->rate)->toBeNull()
        ->and($plan->totalAmount())->toBe(540000)
        ->and(ratesFitColumn($plan))->toBeTrue();
});

it('R3 : la sortie se compare à la fin du plan sur l\'échelle 30/360', function () {
    $calculator = new DepreciationCalculator;
    $input = fn (?string $disposal) => revueInput([
        'originalValue' => 100000, 'inServiceOn' => CarbonImmutable::parse('2024-01-31'),
        'disposedOn' => $disposal === null ? null : CarbonImmutable::parse($disposal),
    ]);

    // Mise en service le 31/01 (= 30/01) : la fin 30/360 est le 29/01/2027.
    expect($calculator->calculate($input('2027-01-29'))->totalAmount())->toBe(100000)
        ->and(lastLine($calculator->calculate($input('2027-01-29')))->netBookValue)->toBe(0)
        ->and($calculator->calculate($input('2027-01-30'))->totalAmount())->toBe(100000)
        ->and($calculator->calculate($input('2027-01-28'))->totalAmount())->toBeLessThan(100000);
});

it('R5 : jours de bord (février, 1er janvier, 30 et 31 décembre) et sorties', function () {
    $calculator = new DepreciationCalculator;
    $firstYearDays = [
        '2023-02-28' => 303, '2024-02-28' => 303, '2024-02-29' => 302, '2024-03-01' => 300,
        '2024-01-01' => 360, '2024-12-31' => 1, '2024-12-30' => 1,
    ];

    foreach ($firstYearDays as $date => $days) {
        $plan = $calculator->calculate(revueInput(['originalValue' => 360000, 'durationYears' => 1, 'inServiceOn' => CarbonImmutable::parse($date)]));

        expect($plan->lines[0]->days)->toBe($days)->and($plan->totalAmount())->toBe(360000);
    }

    $sameDay = $calculator->calculate(revueInput(['disposedOn' => CarbonImmutable::parse('2024-03-18')]));
    $yearEnd = $calculator->calculate(revueInput(['disposedOn' => CarbonImmutable::parse('2025-12-31')]));

    expect($sameDay->lines)->toHaveCount(1)
        ->and($sameDay->lines[0]->days)->toBe(1)
        ->and($sameDay->lines[0]->amount)->toBe(500)
        ->and($yearEnd->totalAmount())->toBe(321500)
        ->and($calculator->calculate(revueInput(['residualValue' => 540000]))->lines)->toBe([]);
});

it('R6 : grands montants acceptés jusqu\'au plafond, refusés au-delà avec un message en français', function () {
    $calculator = new DepreciationCalculator;

    expect($calculator->calculate(revueInput(['originalValue' => 999_999_999_999]))->totalAmount())->toBe(999_999_999_999);

    try {
        $calculator->calculate(revueInput(['originalValue' => 20_000_000_000_000_000]));
        $this->fail('Un montant hors plafond doit être refusé.');
    } catch (InvalidDepreciationInput $exception) {
        expect($exception->field)->toBe('acquisition_cost')
            ->and($exception->getMessage())->toContain('valeur d\'origine');
    }
});

it('R7 : un recalcul impossible donne un message métier et le champ en cause (RG-34, RG-37)', function () {
    $calculator = new DepreciationCalculator;
    $fixed = [2024 => new FixedLine(2024, 141500, days: 283), 2025 => new FixedLine(2025, 180000, days: 360)];
    $cases = [
        'duration_years' => revueInput(['durationYears' => 1, 'fixedLines' => $fixed, 'recalculatedFromYear' => 2026]),
        'in_service_on' => revueInput(['inServiceOn' => CarbonImmutable::parse('2023-06-01'), 'fixedLines' => $fixed, 'recalculatedFromYear' => 2026]),
        'acquisition_cost' => revueInput(['originalValue' => 300000, 'fixedLines' => $fixed, 'recalculatedFromYear' => 2026]),
    ];

    foreach ($cases as $field => $input) {
        try {
            $calculator->calculate($input);
            $this->fail('Plan impossible accepté pour '.$field);
        } catch (ImpossibleDepreciationPlan $exception) {
            expect($exception->field)->toBe($field)
                ->and($exception->getMessage())->not->toContain('Exception');
        }
    }

    // Réduction de durée possible : reste réparti, cumul exact.
    $shorter = $calculator->calculate(revueInput(['durationYears' => 2, 'fixedLines' => $fixed, 'recalculatedFromYear' => 2026]));

    expect($shorter->totalAmount())->toBe(540000)->and(ratesFitColumn($shorter))->toBeTrue();
});
