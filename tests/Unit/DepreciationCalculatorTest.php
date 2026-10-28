<?php

use App\Enums\DepreciationMethod;
use App\Services\Depreciation\DepreciationInput;
use App\Services\Depreciation\DepreciationLineResult;
use App\Services\Depreciation\FixedLine;
use App\Services\Depreciation\ImpossibleDepreciationPlan;
use App\Services\DepreciationCalculator;
use Carbon\CarbonImmutable;

/**
 * RG-40 : les cas de tests/Fixtures/amortissements.json font foi. Ce fichier n'est jamais modifié
 * pour faire passer un test : un écart est un bug du calculateur.
 *
 * @return array<string, array{0: array<string, mixed>}>
 */
function depreciationFixtureCases(): array
{
    $json = json_decode((string) file_get_contents(__DIR__.'/../Fixtures/amortissements.json'), true, flags: JSON_THROW_ON_ERROR);
    $cases = [];

    foreach ($json['cas'] as $case) {
        $cases[$case['id'].' · '.$case['titre']] = [$case];
    }

    return $cases;
}

/**
 * @param  array<string, mixed>  $case
 */
function fixtureInput(array $case, array $overrides = []): DepreciationInput
{
    return new DepreciationInput(...[
        'method' => $case['methode'] === 'degressif' ? DepreciationMethod::Declining : DepreciationMethod::Linear,
        'originalValue' => $case['valeur_origine'],
        'residualValue' => $case['valeur_residuelle'] ?? 0,
        'durationYears' => $case['duree_ans'] ?? 1,
        'inServiceOn' => CarbonImmutable::parse($case['mise_en_service']),
        'decliningCoefficient' => isset($case['coefficient']) ? (string) $case['coefficient'] : null,
        'disposedOn' => isset($case['date_sortie']) ? CarbonImmutable::parse($case['date_sortie']) : null,
        'capitalizationThreshold' => 100000,
        ...$overrides,
    ]);
}

/**
 * @param  list<DepreciationLineResult>  $lines
 * @return list<array<string, int>>
 */
function linesAsFixture(array $lines, string $unit): array
{
    return array_map(fn (DepreciationLineResult $line): array => [
        'exercice' => $line->year,
        $unit => $unit === 'mois' ? $line->months : $line->days,
        'dotation' => $line->amount,
        'cumul' => $line->accumulated,
        'vnc' => $line->netBookValue,
    ], $lines);
}

it('reproduit exactement le cas de référence', function (array $case) {
    $calculator = new DepreciationCalculator;
    $unit = $case['methode'] === 'degressif' ? 'mois' : 'jours';

    if ($case['methode'] === 'aucune') {
        // A8 : sous le seuil d'immobilisation, aucun plan.
        $plan = $calculator->calculate(fixtureInput($case));

        expect($plan->lines)->toBe([])->and($case['attendu'])->toBe([]);

        return;
    }

    if (isset($case['modification'])) {
        // A6 : plan initial, exercices antérieurs à la modification figés, puis nouvelle durée.
        $from = (int) $case['modification']['a_partir_de'];
        $initial = $calculator->calculate(fixtureInput($case));
        $fixed = [];

        foreach ($initial->lines as $line) {
            if ($line->year < $from) {
                $fixed[$line->year] = new FixedLine($line->year, $line->amount, days: $line->days, months: $line->months);
            }
        }

        $plan = $calculator->calculate(fixtureInput($case, [
            'durationYears' => (int) $case['modification']['nouvelle_duree_ans'],
            'fixedLines' => $fixed,
            'recalculatedFromYear' => $from,
        ]));
    } else {
        $plan = $calculator->calculate(fixtureInput($case));
    }

    expect(linesAsFixture($plan->lines, $unit))->toBe($case['attendu']);
})->with(depreciationFixtureCases());

it('respecte les invariants RG-33 et RG-34 sur chaque cas', function (array $case) {
    $plan = (new DepreciationCalculator)->calculate(fixtureInput($case));
    $base = $case['valeur_origine'] - ($case['valeur_residuelle'] ?? 0);
    $total = $plan->totalAmount();

    expect($total)->toBeLessThanOrEqual($base);

    foreach ($plan->lines as $line) {
        expect($line->accumulated)->toBeLessThanOrEqual($base)
            ->and($line->netBookValue)->toBeGreaterThanOrEqual($case['valeur_residuelle'] ?? 0)
            ->and($line->amount)->toBeGreaterThanOrEqual(0);
    }

    if ($plan->lines !== [] && ! isset($case['date_sortie'])) {
        // RG-33 : le cumul final égale exactement la base amortissable.
        expect($total)->toBe($base);
    }
})->with(depreciationFixtureCases());

it('compte le 31 comme le 30 et calcule la première année selon RG-32', function () {
    $calculator = new DepreciationCalculator;
    $plan = $calculator->calculate(new DepreciationInput(
        method: DepreciationMethod::Linear,
        originalValue: 360000,
        residualValue: 0,
        durationYears: 1,
        inServiceOn: CarbonImmutable::parse('2026-01-31'),
    ));

    // (12 − 1) × 30 + (30 − min(31, 30) + 1) = 331 jours, puis 29 jours l'année suivante.
    expect($plan->lines[0]->days)->toBe(331)
        ->and($plan->lines[0]->amount)->toBe(331000)
        ->and($plan->lines[1]->days)->toBe(29)
        ->and($plan->lines[1]->amount)->toBe(29000);

    $disposed = $calculator->calculate(new DepreciationInput(
        method: DepreciationMethod::Linear,
        originalValue: 360000,
        residualValue: 0,
        durationYears: 3,
        inServiceOn: CarbonImmutable::parse('2025-01-01'),
        disposedOn: CarbonImmutable::parse('2026-08-31'),
    ));

    // Sortie le 31/08 : 7 × 30 + 30 = 240 jours.
    expect($disposed->lines[1]->days)->toBe(240)->and($disposed->lines)->toHaveCount(2);
});

it('arrondit au franc, demi-unité vers le haut (RG-33)', function () {
    // 1 000 × 180 / 360 / 2 = 250 ; 1 001 × 180 / 360 / 2 = 250,25 ; 1 002 → 250,5 → 251.
    $calculator = new DepreciationCalculator;
    $amount = fn (int $value): int => $calculator->calculate(new DepreciationInput(
        method: DepreciationMethod::Linear,
        originalValue: $value,
        residualValue: 0,
        durationYears: 2,
        inServiceOn: CarbonImmutable::parse('2026-07-01'),
    ))->lines[0]->amount;

    expect($amount(1000))->toBe(250)
        ->and($amount(1001))->toBe(250)
        ->and($amount(1002))->toBe(251);
});

it('donne le taux linéaire 100 / durée et la date de fin (RG-31)', function () {
    $plan = (new DepreciationCalculator)->calculate(new DepreciationInput(
        method: DepreciationMethod::Linear,
        originalValue: 540000,
        residualValue: 0,
        durationYears: 3,
        inServiceOn: CarbonImmutable::parse('2024-03-18'),
    ));

    expect($plan->rate)->toBe('33.3333')
        ->and($plan->base)->toBe(540000)
        ->and($plan->endsOn->toDateString())->toBe('2027-03-17');
});

it('garde les lignes figées telles quelles (RG-36)', function () {
    $plan = (new DepreciationCalculator)->calculate(new DepreciationInput(
        method: DepreciationMethod::Linear,
        originalValue: 540000,
        residualValue: 0,
        durationYears: 3,
        inServiceOn: CarbonImmutable::parse('2024-03-18'),
        fixedLines: [2024 => new FixedLine(2024, 150000, days: 283)],
    ));

    expect($plan->lines[0]->amount)->toBe(150000)
        ->and($plan->lines[0]->fixed)->toBeTrue()
        ->and($plan->lines[1]->fixed)->toBeFalse()
        ->and(array_sum(array_map(fn (DepreciationLineResult $line): int => $line->amount, $plan->lines)))->toBe(540000);
});

it('refuse un plan impossible sans toucher aux lignes figées (RG-34, RG-37)', function () {
    $calculator = new DepreciationCalculator;

    // Base ramenée sous le cumul déjà figé.
    expect(fn () => $calculator->calculate(new DepreciationInput(
        method: DepreciationMethod::Linear,
        originalValue: 300000,
        residualValue: 0,
        durationYears: 3,
        inServiceOn: CarbonImmutable::parse('2024-03-18'),
        fixedLines: [2024 => new FixedLine(2024, 141500, days: 283), 2025 => new FixedLine(2025, 180000, days: 360)],
        recalculatedFromYear: 2026,
    )))->toThrow(ImpossibleDepreciationPlan::class);

    // Durée raccourcie qui se termine avant l'exercice ouvert alors qu'il reste à amortir.
    expect(fn () => $calculator->calculate(new DepreciationInput(
        method: DepreciationMethod::Linear,
        originalValue: 540000,
        residualValue: 0,
        durationYears: 1,
        inServiceOn: CarbonImmutable::parse('2024-03-18'),
        fixedLines: [2024 => new FixedLine(2024, 141500, days: 283), 2025 => new FixedLine(2025, 180000, days: 360)],
        recalculatedFromYear: 2026,
    )))->toThrow(ImpossibleDepreciationPlan::class);
});

it('refuse le dégressif sous trois ans et sans coefficient (RG-35)', function () {
    $calculator = new DepreciationCalculator;

    expect(fn () => $calculator->calculate(new DepreciationInput(
        method: DepreciationMethod::Declining,
        originalValue: 1000000,
        residualValue: 0,
        durationYears: 2,
        inServiceOn: CarbonImmutable::parse('2024-01-01'),
        decliningCoefficient: '1.5',
    )))->toThrow(InvalidArgumentException::class)
        ->and(fn () => $calculator->calculate(new DepreciationInput(
            method: DepreciationMethod::Declining,
            originalValue: 1000000,
            residualValue: 0,
            durationYears: 5,
            inServiceOn: CarbonImmutable::parse('2024-01-01'),
        )))->toThrow(InvalidArgumentException::class);
});

it('compte les mois du dégressif à partir du mois de mise en service (RG-35)', function () {
    $plan = (new DepreciationCalculator)->calculate(new DepreciationInput(
        method: DepreciationMethod::Declining,
        originalValue: 1000000,
        residualValue: 0,
        durationYears: 5,
        inServiceOn: CarbonImmutable::parse('2024-07-20'),
        decliningCoefficient: '2.0000',
    ));

    $last = $plan->lines[count($plan->lines) - 1];

    // Juillet à décembre : 6 mois, 1 000 000 × 40 % × 6 / 12.
    expect($plan->lines[0]->months)->toBe(6)
        ->and($plan->lines[0]->amount)->toBe(200000)
        ->and($last->months)->toBe(6)
        ->and($last->accumulated)->toBe(1000000)
        ->and($plan->lines)->toHaveCount(6);
});
