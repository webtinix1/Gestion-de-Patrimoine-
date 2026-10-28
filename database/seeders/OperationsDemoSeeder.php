<?php

namespace Database\Seeders;

use App\Actions\Loans\CreateLoan;
use App\Actions\Loans\RecordLoanReturn;
use App\Actions\Maintenance\CloseMaintenanceJob;
use App\Actions\Maintenance\OpenMaintenanceJob;
use App\Actions\Reports\CreateAssetReport;
use App\Actions\Reports\ProcessAssetReport;
use App\Enums\AssetStatus;
use App\Enums\BorrowerType;
use App\Enums\LoanDirection;
use App\Enums\MaintenanceNature;
use App\Enums\MaintenanceResult;
use App\Enums\ProviderType;
use App\Enums\ReportType;
use App\Enums\ReportUrgency;
use App\Models\Asset;
use App\Models\AssetCondition;
use App\Models\AssetReport;
use App\Models\Department;
use App\Models\Loan;
use App\Models\MaintenanceJob;
use App\Models\User;
use App\Services\OperationReferenceGenerator;
use Illuminate\Database\Seeder;

/**
 * Prêts, interventions et signalements de démonstration (E15, E22, E24), dates relatives à aujourd'hui : deux prêts
 * restent toujours en retard. Tout passe par les actions métier ; les biens concernés prennent le bon statut.
 * Idempotent (une référence déjà présente n'est pas recréée). Jamais chargé en production.
 */
class OperationsDemoSeeder extends Seeder
{
    /** @var list<int> */
    private array $used = [];

    public function run(): void
    {
        $manager = User::query()->where('username', 'a.okemba')->first();

        if (! $manager instanceof User) {
            return;
        }

        $previous = auth()->user();
        auth()->setUser($manager);

        try {
            $this->maintenance();
            $this->loans();
            $this->borrowings();
            $this->reports($manager);
        } finally {
            if ($previous instanceof User) {
                auth()->setUser($previous);
            } else {
                auth()->guard()->forgetUser();
            }
        }
    }

    private function maintenance(): void
    {
        $open = app(OpenMaintenanceJob::class);
        $close = app(CloseMaintenanceJob::class);

        // Référence, bien (numéros de la maquette si disponibles), jours de début et de fin, nature, motif, prestataire, coût, résultat, état après.
        /** @var list<array{0: int, 1: list<string>, 2: string|null, 3: int, 4: int|null, 5: MaintenanceNature, 6: string, 7: string, 8: int|null, 9: MaintenanceResult|null, 10: string|null}> $jobs */
        $jobs = [
            [44, ['PAT-000156'], null, -43, -42, MaintenanceNature::Preventive, 'Entretien préventif', 'Music Pro Brazzaville', 40000, MaintenanceResult::Compliant, null],
            [45, ['PAT-000233'], null, -30, -30, MaintenanceNature::PartReplacement, 'Remplacement condensateur', 'Interne (Logistique)', 6500, MaintenanceResult::Repaired, null],
            [47, [], 'Groupe électrogène', -27, -20, MaintenanceNature::Repair, 'Moteur ne démarre plus', 'Énergie Solaire Plus', 120000, MaintenanceResult::Irreparable, 'Hors service'],
            [49, ['PAT-000412'], null, -19, -19, MaintenanceNature::PartReplacement, 'Remplacement du tambour', 'Interne (Informatique)', 38000, MaintenanceResult::Repaired, null],
            [50, ['PAT-000091'], null, 8, null, MaintenanceNature::Diagnostic, 'Diagnostic panne canal B', 'Music Pro Brazzaville', null, null, null],
            [51, [], 'Climatiseur', -8, null, MaintenanceNature::Repair, 'Recharge de gaz, fuite', 'Froid Service Congo', 85000, null, null],
            [52, ['PAT-000003'], null, -14, null, MaintenanceNature::Repair, 'Révision et embrayage', 'Garage Mavoungou', 650000, null, null],
        ];

        foreach ($jobs as [$number, $numbers, $like, $startDay, $endDay, $nature, $reason, $provider, $cost, $result, $conditionAfter]) {
            $startsOn = today()->addDays($startDay);
            $reference = sprintf('MNT-%d-%03d', $startsOn->year, $number);

            if (MaintenanceJob::query()->withoutGlobalScopes()->where('reference', $reference)->exists()) {
                continue;
            }

            // Le climatiseur signalé (SIG-2026-027) appartient à l'Administration, dans le périmètre du responsable qui le signale.
            $asset = $this->asset($numbers, $like, $number === 51 ? 'ADM' : ($number === 47 ? 'LOG' : null));
            $this->references()->syncWith('MNT', $startsOn->year, $number - 1);

            $job = $open([
                'asset_id' => $asset->getKey(),
                'nature' => $nature,
                'reason' => $reason,
                'starts_on' => $startsOn->toDateString(),
                'provider_type' => str_starts_with($provider, 'Interne') ? ProviderType::Internal : ProviderType::Provider,
                'provider_name' => $provider,
                'cost' => $cost,
                'cost_reference' => $number === 52 ? 'Devis GM-D-2026-118' : null,
                'put_in_maintenance' => $number >= 51,
            ]);

            if ($result !== null && $endDay !== null) {
                $close($job, [
                    'result' => $result,
                    'condition_after_id' => $this->condition($conditionAfter ?? $asset->condition->label),
                    'status_after' => $conditionAfter === 'Hors service' ? AssetStatus::OutOfService : AssetStatus::InService,
                    'ends_on' => today()->addDays($endDay)->toDateString(),
                ]);
            }
        }
    }

    private function loans(): void
    {
        $create = app(CreateLoan::class);
        $return = app(RecordLoanReturn::class);

        // Numéro de prêt, bien, sortie, retour prévu, retour effectif (null = en cours), bénéficiaire, département (si interne), contact, état au retour.
        /** @var list<array{0: int, 1: list<string>, 2: int, 3: int, 4: int|null, 5: string, 6: string|null, 7: string|null, 8: string|null}> $loans */
        $loans = [
            [55, ['PAT-000202'], -43, -38, -39, 'Centre de formation', null, null, null],
            [58, ['PAT-000340'], -32, -30, -29, 'Famille Ibara', null, null, 'Dégradé'],
            [60, ['PAT-000087'], -25, -22, -22, 'Paroisse Moungali', null, null, null],
            [61, ['PAT-000156'], -16, -7, null, 'Paroisse Moungali', null, null, null],
            [63, ['PAT-000202'], -8, -2, null, 'Département Jeunesse', 'JEU', null, null],
            [64, ['PAT-000318'], -7, 2, null, 'Chorale Béthel', null, 'Mme Joséphine Mabika · +242 05 000 00 00', null],
            [65, ['PAT-000340'], -4, 1, null, 'Famille Okandze (mariage)', null, null, null],
            [66, ['PAT-000129'], -3, 11, null, 'Département Communication', 'COM', null, null],
            [67, ['PAT-000611'], -3, 18, null, 'Annexe Talangaï', null, null, null],
        ];

        foreach ($loans as [$number, $numbers, $outDay, $dueDay, $backDay, $name, $department, $contact, $back]) {
            $outOn = today()->addDays($outDay);

            if (Loan::query()->withoutGlobalScopes()->where('reference', sprintf('PRT-%d-%03d', $outOn->year, $number))->exists()) {
                continue;
            }

            $asset = $this->asset($numbers);
            $this->references()->syncWith('PRT', $outOn->year, $number - 1);

            $loan = $create([
                'direction' => LoanDirection::Out,
                'borrower_type' => $department === null ? BorrowerType::External : BorrowerType::Internal,
                'borrower_name' => $name,
                'borrower_contact' => $contact,
                'department_id' => $department === null ? null : Department::query()->withoutGlobalScopes()->where('code', $department)->value('id'),
                'out_on' => $outOn->toDateString(),
                'due_on' => today()->addDays($dueDay)->toDateString(),
                'responsible_id' => auth()->id(),
            ], [$asset->getKey()]);

            if ($backDay !== null) {
                $item = $loan->items->sole();
                $worse = $back === 'Dégradé'
                    ? AssetCondition::query()->where('sort', '>', (int) $item->conditionOut->sort)->orderBy('sort')->first()
                    : null;

                $return($loan, [
                    'returned_on' => today()->addDays($backDay)->toDateString(),
                    'items' => [$item->getKey() => [
                        'condition_back_id' => $worse?->getKey() ?? $item->condition_out_id,
                        'return_notes' => $worse === null ? null : '3 éléments abîmés au retour.',
                    ]],
                ]);
            }
        }
    }

    private function borrowings(): void
    {
        $create = app(CreateLoan::class);

        // RG-58 : emprunts auprès de tiers, référence EXT- de même rang que l'emprunt.
        $borrowings = [
            [2, 'Chaises (lot de 200)', 'Société Event Pro (location)', 1000000, -27, 3],
            [3, 'Écran LED 3 x 2 m', 'Église sœur de Bacongo (prêteur)', 2200000, -18, 2],
            [4, 'Podium mobile 8 x 6 m', 'Mairie de Moungali (prêteur)', 3500000, -10, 5],
        ];

        foreach ($borrowings as [$number, $designation, $lender, $value, $outDay, $dueDay]) {
            $outOn = today()->addDays($outDay);

            if (Loan::query()->withoutGlobalScopes()->where('reference', sprintf('EMP-%d-%03d', $outOn->year, $number))->exists()) {
                continue;
            }

            $this->references()->syncWith('EMP', $outOn->year, $number - 1);
            $this->references()->syncExternal($number - 1);

            $create([
                'direction' => LoanDirection::In,
                'borrower_name' => $lender,
                'external_designation' => $designation,
                'external_value' => $value,
                'condition_out_id' => $this->condition('Bon état'),
                'out_on' => $outOn->toDateString(),
                'due_on' => today()->addDays($dueDay)->toDateString(),
                'responsible_id' => auth()->id(),
            ]);
        }
    }

    private function reports(User $manager): void
    {
        $process = app(ProcessAssetReport::class);

        // Numéro, signalant, bien (numéro ou désignation, département), nature, urgence, état ou département indiqué, description, ancienneté en jours.
        /** @var list<array{0: int, 1: string, 2: string|null, 3: ReportType, 4: ReportUrgency, 5: string, 6: int}> $reports */
        $reports = [
            [25, 's.ondongo', null, ReportType::Condition, ReportUrgency::Normal, 'Tourne très lentement. Réparé par la Logistique.', 21],
            [26, 'e.mbemba', null, ReportType::Maintenance, ReportUrgency::Normal, 'Le micro grésille parfois pendant le culte.', 18],
            [27, 'r.bouanga', 'job-51', ReportType::Condition, ReportUrgency::Normal, 'Ne refroidit plus, fuite d\'eau sous l\'unité intérieure.', 9],
            [29, 'p.ngoyi', null, ReportType::Move, ReportUrgency::Normal, 'Les deux tables sont utilisées en permanence par la Jeunesse depuis septembre.', 5],
            [30, 'e.mbemba', null, ReportType::Maintenance, ReportUrgency::Normal, 'Le canal B grésille puis coupe après 20 minutes d\'utilisation.', 4],
            [31, 'j.nkounkou', 'genset', ReportType::Condition, ReportUrgency::Urgent, 'Ne démarre plus malgré la réparation. Le prestataire le déclare irréparable.', 3],
        ];

        foreach ($reports as [$number, $username, $target, $type, $urgency, $description, $age]) {
            $createdAt = today()->subDays($age)->setTime(9, 30);
            $reference = sprintf('SIG-%d-%03d', $createdAt->year, $number);

            if (AssetReport::query()->withoutGlobalScopes()->where('reference', $reference)->exists()) {
                continue;
            }

            $reporter = User::query()->where('username', $username)->first();

            if (! $reporter instanceof User) {
                continue;
            }

            auth()->setUser($reporter);

            try {
                $asset = $target === 'job-51'
                    ? Asset::query()->find(MaintenanceJob::query()->withoutGlobalScopes()->whereDate('starts_on', today()->subDays(8))->value('asset_id'))
                    : $this->reportedAsset($target);

                if (! $asset instanceof Asset) {
                    continue;
                }

                $this->references()->syncWith('SIG', $createdAt->year, $number - 1);

                $report = app(CreateAssetReport::class)([
                    'asset_id' => $asset->getKey(),
                    'type' => $type,
                    'urgency' => $urgency,
                    'description' => $description,
                    'reported_condition_id' => $type === ReportType::Condition
                        ? $this->differentCondition($asset, $number === 31 ? 'Hors service' : 'À réparer')
                        : null,
                    'reported_department_id' => $type === ReportType::Move ? Department::query()->withoutGlobalScopes()->where('code', 'JEU')->value('id') : null,
                ]);
            } finally {
                auth()->setUser($manager);
            }

            AssetReport::query()->whereKey($report->getKey())->update(['created_at' => $createdAt, 'updated_at' => $createdAt]);

            match ($number) {
                25 => $process->updateCondition($report, (int) $report->reported_condition_id),
                26 => $process->reject($report, 'Usure normale : à signaler de nouveau si la panne se répète.'),
                27 => $process->linkOperation(
                    $report->getKey(),
                    $asset,
                    MaintenanceJob::query()->withoutGlobalScopes()->where('asset_id', $asset->getKey())->orderByDesc('id')->firstOrFail(),
                ),
                default => null,
            };
        }
    }

    private function reportedAsset(?string $target): ?Asset
    {
        // Le signalant ne voit que les biens de son périmètre (scope global).
        return Asset::query()
            ->whereNull('disposed_at')
            ->whereNotIn('number', $this->reserved())
            ->whereNotIn('id', $this->used)
            ->when($target === 'genset', fn ($query) => $query->where('designation', 'like', '%Groupe électrogène%'))
            ->orderBy('id')
            ->first()
            ?? Asset::query()->whereNull('disposed_at')->whereNotIn('number', $this->reserved())->whereNotIn('id', $this->used)->orderBy('id')->first();
    }

    /**
     * @param  list<string>  $numbers
     */
    private function asset(array $numbers, ?string $like = null, ?string $department = null): Asset
    {
        $available = fn () => Asset::query()
            ->whereNull('disposed_at')
            ->whereIn('status', [AssetStatus::InService, AssetStatus::InStock])
            ->whereNotIn('number', $this->reserved())
            ->whereNotIn('id', $this->used);

        $asset = null;

        foreach ($numbers as $number) {
            $asset ??= $available()->where('number', $number)->first();
        }

        $inDepartment = fn () => $available()
            ->when($department, fn ($query, string $code) => $query->whereHas('department', fn ($department) => $department->where('code', $code)));

        $asset ??= $inDepartment()
            ->when($like, fn ($query, string $like) => $query->where('designation', 'like', "%{$like}%"))
            ->orderBy('id')
            ->first()
            ?? $inDepartment()->orderBy('id')->first()
            ?? $available()->orderBy('id')->firstOrFail();

        $this->used[] = $asset->getKey();

        return $asset;
    }

    /**
     * Biens imposés par les autres démonstrations : leur état et leur statut ne bougent pas.
     *
     * @return list<string>
     */
    private function reserved(): array
    {
        return array_column(AssetsDemoSeeder::FIXED, 'number');
    }

    // Un signalement doit indiquer un état différent de celui de la fiche (RG-63).
    private function differentCondition(Asset $asset, string $wanted): int
    {
        return $this->condition($asset->condition->label === $wanted ? 'État moyen' : $wanted);
    }

    private function condition(string $label): int
    {
        return (int) AssetCondition::query()->where('label', $label)->value('id');
    }

    private function references(): OperationReferenceGenerator
    {
        return app(OperationReferenceGenerator::class);
    }
}
