<?php

namespace App\Actions\FiscalYears;

use App\Actions\Depreciation\RecalculateDepreciationSchedules;
use App\Enums\FiscalYearStatus;
use App\Enums\PermissionAbility;
use App\Models\Asset;
use App\Models\DepreciationLine;
use App\Models\DepreciationSchedule;
use App\Models\FiscalYear;
use App\Models\User;
use App\Services\FiscalYearClosing\AttentionPoint;
use App\Services\FiscalYearClosing\FiscalYearAttentionPoints;
use App\Support\DepreciationAuthority;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Clôture d'un exercice (E33).
 *
 * - RG-41 : par l'administrateur ou le comptable, à partir du dernier jour de l'exercice.
 * - RG-42 : points d'attention affichés, à confirmer ; ils n'empêchent pas la clôture.
 * - RG-43 : fige les lignes de l'exercice, ouvre l'exercice suivant, ne s'annule pas.
 * - RG-38 : les plans courants de l'exercice sont validés par la clôture.
 */
class CloseFiscalYear
{
    public function __construct(
        private FiscalYearAttentionPoints $attentionPoints,
        private RecalculateDepreciationSchedules $recalculate,
    ) {}

    public function __invoke(FiscalYear $fiscalYear, User $user, bool $acknowledged = false, ?CarbonImmutable $today = null): FiscalYear
    {
        if (! DepreciationAuthority::canManage($user, PermissionAbility::Validate)) {
            throw new AuthorizationException('Seuls l\'administrateur et le comptable peuvent clôturer un exercice (RG-41).');
        }

        $today ??= CarbonImmutable::today();

        // Rejouée en cas d'interblocage avec une sortie du patrimoine (création de l'exercice suivant, lot 10).
        return DB::transaction(function () use ($fiscalYear, $user, $acknowledged, $today): FiscalYear {
            $current = FiscalYear::query()->lockForUpdate()->findOrFail($fiscalYear->getKey());

            if ($current->status !== FiscalYearStatus::Open) {
                throw ValidationException::withMessages(['year' => 'Seul un exercice ouvert peut être clôturé.']);
            }

            if ($today->toDateString() < $current->ends_on->toDateString()) {
                throw ValidationException::withMessages([
                    'year' => 'L\'exercice '.$current->year.' pourra être clôturé à partir du '.$current->ends_on->format('d/m/Y').' (RG-41).',
                ]);
            }

            $previous = FiscalYear::query()->where('year', '<', $current->year)->notClosed()->orderBy('year')->first();

            if ($previous !== null) {
                throw ValidationException::withMessages(['year' => 'Clôturez d\'abord l\'exercice '.$previous->year.'.']);
            }

            $points = $this->attentionPoints->for($current);

            if (FiscalYearAttentionPoints::needConfirmation($points) && ! $acknowledged) {
                throw ValidationException::withMessages([
                    'acknowledged' => 'Confirmez avoir pris connaissance des points d\'attention avant de clôturer (RG-42).',
                ]);
            }

            // Les plans sont mis à jour une dernière fois avant d'être figés.
            $report = ($this->recalculate)();

            DepreciationLine::query()
                ->where('fiscal_year', $current->year)
                ->where('is_locked', false)
                ->update(['is_locked' => true, 'updated_at' => now()]);

            $toValidate = DepreciationSchedule::query()
                ->where('is_current', true)
                ->whereNull('validated_at')
                ->whereHas('lines', fn ($query) => $query->where('fiscal_year', $current->year));
            // RG-70 : la liste des biens dont le plan est validé par la clôture figure au journal.
            $validatedNumbers = Asset::query()->withoutGlobalScopes()
                ->whereIn('id', (clone $toValidate)->select('asset_id'))
                ->orderBy('number')
                ->pluck('number')
                ->all();
            $validated = $toValidate->update(['validated_by' => $user->getKey(), 'validated_at' => now(), 'updated_at' => now()]);

            $current->forceFill([
                'status' => FiscalYearStatus::Closed,
                'closed_by' => $user->getKey(),
                'closed_at' => now(),
                'closing_notes' => [
                    'attention_points' => array_map(fn (AttentionPoint $point): array => $point->toArray(), $points),
                    'acknowledged' => $acknowledged,
                    'validated_schedules' => $validated,
                    'anomalies' => $report['anomalies'],
                ],
            ])->save();

            $next = FiscalYear::query()->where('year', $current->year + 1)->first();

            if ($next === null) {
                app(CreateFiscalYear::class)($current->year + 1, FiscalYearStatus::Open);
            } elseif ($next->status === FiscalYearStatus::Prepared) {
                app(OpenFiscalYear::class)($next);
            }

            // RG-70 : clôture d'exercice.
            activity('amortissements')
                ->performedOn($current)
                ->causedBy($user)
                ->event('fiscal_year_closed')
                ->withProperties([
                    'old' => ['status' => FiscalYearStatus::Open->getLabel()],
                    'attributes' => ['status' => FiscalYearStatus::Closed->getLabel(), 'plans_validated' => $validated],
                    'validated_assets' => $validatedNumbers,
                    'attention_points' => array_map(fn (AttentionPoint $point): string => $point->label, $points),
                ])
                ->log('Clôture de l\'exercice '.$current->year);

            $fiscalYear->setRawAttributes($current->getAttributes(), sync: true);

            return $fiscalYear;
        }, attempts: 3);
    }
}
