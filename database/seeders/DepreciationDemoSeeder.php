<?php

namespace Database\Seeders;

use App\Actions\Depreciation\RecalculateDepreciationSchedules;
use App\Enums\FiscalYearStatus;
use App\Models\DepreciationSchedule;
use App\Models\FiscalYear;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;

/**
 * Démonstration des amortissements : exercices 2023 à 2025 clôturés par le comptable, 2026 ouvert,
 * plans générés pour tous les biens amortissables. PAT-000125, PAT-000034 et PAT-001248 reprennent
 * les cas A2, A5 (sans la sortie) et A3. Idempotent. Jamais chargé en production.
 */
class DepreciationDemoSeeder extends Seeder
{
    /**
     * Exercice => date de clôture (null : exercice ouvert).
     *
     * @var array<int, string|null>
     */
    public const array FISCAL_YEARS = [
        2023 => '2024-01-08 10:00:00',
        2024 => '2025-01-05 10:00:00',
        2025 => '2026-01-05 10:00:00',
        2026 => null,
    ];

    public function run(): void
    {
        $accountant = User::query()->where('username', 'm.loubaki')->first();

        activity()->withoutLogs(function () use ($accountant): void {
            foreach (self::FISCAL_YEARS as $year => $closedAt) {
                if (FiscalYear::query()->where('year', $year)->exists()) {
                    continue;
                }

                $fiscalYear = new FiscalYear([
                    'year' => $year,
                    'label' => (string) $year,
                    'starts_on' => $year.'-01-01',
                    'ends_on' => $year.'-12-31',
                    'status' => $closedAt === null ? FiscalYearStatus::Open : FiscalYearStatus::Closed,
                ]);
                $fiscalYear->forceFill([
                    'closed_by' => $closedAt === null ? null : $accountant?->id,
                    'closed_at' => $closedAt,
                    'closing_notes' => $closedAt === null ? null : ['acknowledged' => true, 'attention_points' => [], 'demo' => true],
                ])->save();
            }

            app(RecalculateDepreciationSchedules::class)();

            // RG-38 : les plans des exercices clôturés ont été validés à la clôture de 2025.
            DepreciationSchedule::query()
                ->where('is_current', true)
                ->whereNull('validated_at')
                ->whereHas('lines', fn ($query) => $query->where('is_locked', true))
                ->update(['validated_by' => $accountant?->id, 'validated_at' => Carbon::parse((string) self::FISCAL_YEARS[2025])]);
        });
    }
}
