<?php

namespace App\Services;

use App\Enums\DepreciationMethod;
use App\Services\Depreciation\DepreciationInput;
use App\Services\Depreciation\DepreciationLineResult;
use App\Services\Depreciation\DepreciationPlan;
use App\Services\Depreciation\FixedLine;
use App\Services\Depreciation\ImpossibleDepreciationPlan;
use App\Services\Depreciation\InvalidDepreciationInput;
use App\Support\Money;
use Carbon\CarbonImmutable;
use InvalidArgumentException;

/**
 * Calcul pur d'un plan d'amortissement (RG-32 à RG-37, RG-39) : aucun accès à la base, aucune
 * horloge. Exercices = années civiles (Q5). Tous les calculs sont faits en entiers, jamais en float.
 * Les cas de tests/Fixtures/amortissements.json font foi (RG-40).
 */
final class DepreciationCalculator
{
    private const int DAYS_PER_YEAR = 360;

    private const int MONTHS_PER_YEAR = 12;

    // Taux et coefficients : 4 décimales, comme les colonnes decimal(7,4).
    private const int SCALE = 10000;

    public const int MAX_AMOUNT = 999_999_999_999;

    // Coefficient dégressif maximal (× 10 000) : le taux du plan tient dans decimal(7,4).
    public const int MAX_COEFFICIENT = 100_000;

    public function calculate(DepreciationInput $input): DepreciationPlan
    {
        $this->validate($input);

        $start = $input->inServiceOn->startOfDay();
        $end = $start->addYearsNoOverflow($input->durationYears)->subDay();
        $coefficient = $input->method === DepreciationMethod::Declining
            ? self::parseScaled((string) $input->decliningCoefficient)
            : null;
        $rate = $coefficient === null
            ? self::rate(1, $input->durationYears)
            : self::rate($coefficient, self::SCALE * $input->durationYears);

        // RG-30 : sous le seuil d'immobilisation, le bien est suivi sans plan (cas A8).
        if ($input->originalValue < $input->capitalizationThreshold || $input->base() <= 0) {
            return new DepreciationPlan(max(0, $input->base()), $rate, $start, $end, []);
        }

        if ($input->disposedOn !== null && self::index360($input->disposedOn) < self::index360($start)) {
            return new DepreciationPlan($input->base(), $rate, $start, $end, $this->onlyFixed($input));
        }

        $lines = $coefficient === null
            ? $this->linear($input, $start, $end)
            : $this->declining($input, $start, $end, $coefficient);

        return new DepreciationPlan($input->base(), $rate, $start, $end, $lines);
    }

    /**
     * RG-32 : jour de l'année sur 360 jours, mois de 30 jours, le 31 compte comme le 30.
     */
    public static function dayOfYear360(CarbonImmutable $date): int
    {
        return ($date->month - 1) * 30 + min($date->day, 30);
    }

    /**
     * Rang d'un jour sur l'échelle 30/360 (année × 360 + jour de l'année), pour comparer les dates
     * comme le fait le prorata : le 31 et le 30 d'un mois sont le même jour.
     */
    public static function index360(CarbonImmutable $date): int
    {
        return $date->year * self::DAYS_PER_YEAR + self::dayOfYear360($date);
    }

    /**
     * RG-33 : arrondi au franc le plus proche, demi-unité vers le haut, en entiers.
     */
    public static function roundHalfUp(int $numerator, int $denominator): int
    {
        if ($denominator <= 0) {
            throw new InvalidArgumentException('Dénominateur nul ou négatif.');
        }

        if ($numerator < 0) {
            return -self::roundHalfUp(-$numerator, $denominator);
        }

        return intdiv(2 * $numerator + $denominator, 2 * $denominator);
    }

    /**
     * Décimal positif (« 2 », « 2,5 », « 1.5000 ») en entier à 4 décimales.
     */
    public static function parseScaled(string $value): int
    {
        $value = str_replace(',', '.', trim($value));

        if (preg_match('/^(\d{1,6})(?:\.(\d{1,4}))?$/', $value, $matches) !== 1) {
            throw new InvalidArgumentException("Coefficient invalide : « {$value} ».");
        }

        return (int) $matches[1] * self::SCALE + (int) str_pad($matches[2] ?? '', 4, '0');
    }

    public static function formatScaled(int $value): string
    {
        return sprintf('%d.%04d', intdiv($value, self::SCALE), $value % self::SCALE);
    }

    /**
     * Taux en pourcentage = 100 × numérateur / dénominateur, à 4 décimales.
     */
    private static function rate(int $numerator, int $denominator): string
    {
        return self::formatScaled(self::roundHalfUp($numerator * 100 * self::SCALE, $denominator));
    }

    private function validate(DepreciationInput $input): void
    {
        if ($input->durationYears < 1 || $input->durationYears > 100) {
            throw new InvalidDepreciationInput('La durée d\'amortissement doit être comprise entre 1 et 100 ans.', 'duration_years');
        }

        // Plafond du formulaire : garantit des calculs entiers sans débordement.
        if ($input->originalValue < 0 || $input->originalValue > self::MAX_AMOUNT) {
            throw new InvalidDepreciationInput('La valeur d\'origine doit être comprise entre 0 et 999 999 999 999 FCFA.', 'acquisition_cost');
        }

        if ($input->residualValue < 0 || $input->residualValue > $input->originalValue) {
            throw new InvalidDepreciationInput('La valeur résiduelle doit être comprise entre 0 et la valeur d\'origine.', 'residual_value');
        }

        if ($input->method === DepreciationMethod::Declining) {
            if ($input->decliningCoefficient === null || self::parseScaled($input->decliningCoefficient) <= 0
                || self::parseScaled($input->decliningCoefficient) > self::MAX_COEFFICIENT) {
                throw new InvalidDepreciationInput('La méthode dégressive exige un coefficient compris entre 0 et 10.', 'depreciation_method');
            }

            // RG-35 : coefficients prévus à partir de 3 ans seulement.
            if ($input->durationYears < 3) {
                throw new InvalidDepreciationInput('La méthode dégressive s\'applique à une durée d\'au moins 3 ans (RG-35).', 'duration_years');
            }
        }

        foreach ($input->fixedLines as $year => $line) {
            if ($line->year !== $year || $line->amount < 0) {
                throw new InvalidDepreciationInput('La ligne figée de l\'exercice '.$year.' est incohérente : contactez l\'administrateur.', 'duration_years');
            }
        }
    }

    /**
     * Unités (jours ou mois) de chaque exercice du plan complet, sans sortie.
     *
     * @return array<int, int>
     */
    private function periods(int $startYear, int $firstYearUnits, int $totalUnits, int $unitsPerYear): array
    {
        $periods = [];
        $remaining = $totalUnits;
        $year = $startYear;

        while ($remaining > 0) {
            $units = min($year === $startYear ? $firstYearUnits : $unitsPerYear, $remaining);
            $periods[$year] = $units;
            $remaining -= $units;
            $year++;
        }

        return $periods;
    }

    /**
     * RG-39 : à la sortie, la dotation de l'exercice est arrêtée à la date de sortie incluse.
     *
     * @param  array<int, int>  $periods
     * @return array{0: array<int, int>, 1: bool}
     */
    private function applyDisposal(array $periods, ?CarbonImmutable $disposedOn, bool $beforeEnd, callable $unitsUntil): array
    {
        if ($disposedOn === null || ! $beforeEnd) {
            return [$periods, false];
        }

        $year = $disposedOn->year;
        $cut = [];

        foreach ($periods as $periodYear => $units) {
            if ($periodYear < $year) {
                $cut[$periodYear] = $units;
            } elseif ($periodYear === $year) {
                $cut[$periodYear] = min($units, max(0, $unitsUntil($disposedOn)));
            }
        }

        return [$cut, true];
    }

    /**
     * @param  array<int, int>  $periods
     * @return list<int>
     */
    private function years(DepreciationInput $input, array $periods): array
    {
        $years = array_unique([...array_keys($input->fixedLines), ...array_keys($periods)]);
        sort($years);

        $computed = array_values(array_diff($years, array_keys($input->fixedLines)));

        // RG-36 : seuls les exercices postérieurs aux exercices figés se calculent.
        if ($computed !== [] && $input->fixedLines !== [] && max(array_keys($input->fixedLines)) > min($computed)) {
            throw new ImpossibleDepreciationPlan('Avec cette date de mise en service, il faudrait ajouter une dotation à un exercice clôturé ou antérieur, ce qui est interdit (RG-36).', 'in_service_on');
        }

        return $years;
    }

    /**
     * Méthode linéaire (RG-32, RG-33, RG-34) et changement prospectif (RG-37, cas A6).
     *
     * @return list<DepreciationLineResult>
     */
    private function linear(DepreciationInput $input, CarbonImmutable $start, CarbonImmutable $end): array
    {
        $base = $input->base();
        $totalDays = $input->durationYears * self::DAYS_PER_YEAR;
        $startIndex = self::dayOfYear360($start);
        // RG-32 : jours de la première année = (12 − mois) × 30 + (30 − min(jour, 30) + 1).
        $normal = $this->periods($start->year, self::DAYS_PER_YEAR - $startIndex + 1, $totalDays, self::DAYS_PER_YEAR);
        $endYear = (int) array_key_last($normal);

        // RG-39 : sortie comparée à la fin du plan sur l'échelle 30/360 (fin = début + durée × 360 − 1).
        $beforeEnd = $input->disposedOn !== null
            && self::index360($input->disposedOn) < self::index360($start) + $totalDays - 1;

        [$periods, $cut] = $this->applyDisposal($normal, $input->disposedOn, $beforeEnd, fn (CarbonImmutable $date): int => self::dayOfYear360($date)
            - ($date->year === $start->year ? $startIndex : 1) + 1);

        $anchor = $input->recalculatedFromYear !== null && $input->recalculatedFromYear > $start->year
            ? $input->recalculatedFromYear
            : null;
        $anchorBase = null;
        $anchorDays = null;
        $linearRate = self::rate(1, $input->durationYears);
        $accumulated = 0;
        $lines = [];

        foreach ($this->years($input, $periods) as $year) {
            if ($anchor !== null && $anchorBase === null && $year >= $anchor) {
                // RG-37 : la VNC au début du premier exercice ouvert est répartie sur la durée restante recalculée.
                $anchorBase = $base - $accumulated;
                $anchorDays = $totalDays - array_sum(array_filter($normal, fn (int $y): bool => $y < $anchor, ARRAY_FILTER_USE_KEY));

                if ($anchorDays <= 0 && $anchorBase > 0) {
                    throw new ImpossibleDepreciationPlan('Avec cette durée, l\'amortissement se terminerait avant l\'exercice '.$anchor.' alors qu\'il reste '.Money::format($anchorBase, withCurrency: true).' à amortir : choisissez une durée plus longue.', 'duration_years');
                }
            }

            $fixed = $input->fixedLines[$year] ?? null;

            if ($fixed instanceof FixedLine) {
                $amount = $fixed->amount;
                $days = $fixed->days;
                $lineBase = $base;
                $lineRate = $linearRate;
            } else {
                $days = $periods[$year];

                if ($anchorBase !== null && $anchorDays !== null && $anchorDays > 0) {
                    $amount = self::roundHalfUp($anchorBase * $days, $anchorDays);
                    $lineBase = $anchorBase;
                    // Taux annuel sans sens comptable sur une durée restante recalculée (il peut dépasser 100 %) : non renseigné.
                    $lineRate = null;
                } else {
                    // RG-32 : dotation = base × jours / 360 / durée.
                    $amount = self::roundHalfUp($base * $days, $totalDays);
                    $lineBase = $base;
                    $lineRate = $linearRate;
                }

                // RG-33 : la dernière dotation absorbe l'écart d'arrondi.
                if ($year === $endYear && ! $cut) {
                    $amount = $base - $accumulated;
                }

                // RG-34 : le cumul ne dépasse jamais la base amortissable.
                $amount = max(0, min($amount, $base - $accumulated));
            }

            $accumulated += $amount;
            $lines[] = $this->line($input, $year, $days, null, $lineBase, $lineRate, $amount, $accumulated, $fixed !== null);
        }

        $this->assertComplete($input, $accumulated, $cut);

        return $lines;
    }

    /**
     * RG-35 : dégressif, prorata en mois à partir du mois de mise en service, bascule en linéaire
     * dès que l'annuité linéaire sur la durée restante est supérieure (cas A7, provisoire).
     *
     * @return list<DepreciationLineResult>
     */
    private function declining(DepreciationInput $input, CarbonImmutable $start, CarbonImmutable $end, int $coefficient): array
    {
        $base = $input->base();
        $duration = $input->durationYears;
        $totalMonths = $duration * self::MONTHS_PER_YEAR;
        $normal = $this->periods($start->year, self::MONTHS_PER_YEAR - $start->month + 1, $totalMonths, self::MONTHS_PER_YEAR);
        $endYear = (int) array_key_last($normal);

        // Sortie dans le dernier mois du plan : l'exercice est complet.
        $beforeEnd = $input->disposedOn !== null
            && $input->disposedOn->year * 12 + $input->disposedOn->month < $start->year * 12 + $start->month + $totalMonths - 1;

        [$periods, $cut] = $this->applyDisposal($normal, $input->disposedOn, $beforeEnd, fn (CarbonImmutable $date): int => $date->month
            - ($date->year === $start->year ? $start->month : 1) + 1);

        $decliningRate = self::rate($coefficient, self::SCALE * $duration);
        $accumulated = 0;
        $lines = [];

        foreach ($this->years($input, $periods) as $year) {
            $remainingBase = $base - $accumulated;
            $remainingMonths = $totalMonths - array_sum(array_filter($normal, fn (int $y): bool => $y < $year, ARRAY_FILTER_USE_KEY));
            $fixed = $input->fixedLines[$year] ?? null;
            $lineRate = $decliningRate;

            if ($fixed instanceof FixedLine) {
                $amount = $fixed->amount;
                $months = $fixed->months;
            } else {
                $months = $periods[$year];
                $declining = self::roundHalfUp($remainingBase * $coefficient * $months, self::SCALE * $duration * self::MONTHS_PER_YEAR);
                $linear = $remainingMonths > 0 ? self::roundHalfUp($remainingBase * $months, $remainingMonths) : $remainingBase;
                $amount = $declining;

                if ($linear > $declining) {
                    $amount = $linear;
                    // Annuité linéaire sur la durée restante : pas de taux annuel propre (jusqu'à 1 200 % le dernier mois).
                    $lineRate = null;
                }

                // RG-33 : la dernière dotation absorbe le reste.
                if ($year === $endYear && ! $cut) {
                    $amount = $remainingBase;
                }

                $amount = max(0, min($amount, $remainingBase));
            }

            $accumulated += $amount;
            $lines[] = $this->line($input, $year, null, $months, $remainingBase, $lineRate, $amount, $accumulated, $fixed !== null);
        }

        $this->assertComplete($input, $accumulated, $cut);

        return $lines;
    }

    private function line(DepreciationInput $input, int $year, ?int $days, ?int $months, int $base, ?string $rate, int $amount, int $accumulated, bool $fixed): DepreciationLineResult
    {
        if ($accumulated > $input->base()) {
            throw new ImpossibleDepreciationPlan('Les dotations des exercices clôturés atteignent déjà '.Money::format($accumulated, withCurrency: true).', plus que la nouvelle base amortissable ('.Money::format($input->base(), withCurrency: true).') : elles ne peuvent pas être reprises (RG-36).', 'acquisition_cost');
        }

        return new DepreciationLineResult(
            year: $year,
            days: $days,
            months: $months,
            base: $base,
            rate: $rate,
            amount: $amount,
            accumulated: $accumulated,
            netBookValue: $input->originalValue - $accumulated,
            fixed: $fixed,
        );
    }

    private function assertComplete(DepreciationInput $input, int $accumulated, bool $cut): void
    {
        // RG-33 : sans sortie, le cumul final égale exactement la base amortissable.
        if (! $cut && $accumulated !== $input->base()) {
            throw new ImpossibleDepreciationPlan('Avec ces paramètres, la base ne peut pas être amortie entièrement sans modifier un exercice clôturé : allongez la durée.', 'duration_years');
        }
    }

    /**
     * @return list<DepreciationLineResult>
     */
    private function onlyFixed(DepreciationInput $input): array
    {
        $accumulated = 0;
        $lines = [];
        $years = array_keys($input->fixedLines);
        sort($years);

        foreach ($years as $year) {
            $fixed = $input->fixedLines[$year];
            $accumulated += $fixed->amount;
            $lines[] = $this->line($input, $year, $fixed->days, $fixed->months, $input->base(), self::rate(1, $input->durationYears), $fixed->amount, $accumulated, true);
        }

        return $lines;
    }
}
