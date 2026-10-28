<?php

use App\Enums\AssetStatus;
use App\Enums\LoanDirection;
use App\Enums\LoanStatus;
use App\Enums\MaintenanceResult;
use App\Enums\MaintenanceStatus;
use App\Enums\ReportStatus;
use App\Models\Asset;
use App\Models\AssetReport;
use App\Models\Loan;
use App\Models\LoanItem;
use App\Models\MaintenanceJob;
use Database\Seeders\DemoSeeder;
use Database\Seeders\OperationsDemoSeeder;
use Database\Seeders\ReferenceSeeder;
use Database\Seeders\RolesSeeder;

it('charge les prêts, interventions et signalements de la maquette, de façon idempotente', function () {
    $this->seed(RolesSeeder::class);
    $this->seed(ReferenceSeeder::class);
    $this->seed(DemoSeeder::class);

    $loans = Loan::query();
    $ongoing = (clone $loans)->where('direction', LoanDirection::Out)->where('status', LoanStatus::Ongoing);

    // Deux prêts en retard, six prêts en cours, trois emprunts, trois prêts rendus.
    expect($ongoing->count())->toBe(6)
        ->and((clone $ongoing)->whereDate('due_on', '<', today())->count())->toBe(2)
        ->and((clone $loans)->where('direction', LoanDirection::In)->count())->toBe(3)
        ->and((clone $loans)->where('status', LoanStatus::Returned)->count())->toBe(3)
        ->and(Loan::query()->where('reference', 'like', 'PRT-%')->pluck('reference')->contains(fn (string $reference): bool => str_ends_with($reference, '-064')))->toBeTrue()
        ->and(LoanItem::query()->whereNull('asset_id')->pluck('external_reference')->sort()->values()->all())->toBe(['EXT-0002', 'EXT-0003', 'EXT-0004']);

    // Les biens prêtés sont Emprunté ; les biens rendus ont retrouvé leur statut.
    $loaned = LoanItem::query()->whereHas('loan', fn ($query) => $query->where('status', LoanStatus::Ongoing))->whereNotNull('asset_id')->pluck('asset_id');
    $returned = LoanItem::query()->whereHas('loan', fn ($query) => $query->where('status', LoanStatus::Returned))->pluck('asset_id');

    expect(Asset::query()->whereIn('id', $loaned)->pluck('status')->unique()->all())->toBe([AssetStatus::OnLoan])
        ->and(Asset::query()->whereIn('id', $returned->diff($loaned))->where('status', AssetStatus::OnLoan)->count())->toBe(0);

    // Interventions : numéros 044 à 052, deux en cours avec leur bien En maintenance, une planifiée, une irréparable.
    $jobs = MaintenanceJob::query()->get();

    expect($jobs)->toHaveCount(7)
        ->and($jobs->where('status', MaintenanceStatus::Ongoing)->count())->toBe(2)
        ->and($jobs->where('status', MaintenanceStatus::Planned)->count())->toBe(1)
        ->and($jobs->where('result', MaintenanceResult::Irreparable)->count())->toBe(1)
        ->and($jobs->pluck('reference')->map(fn (string $reference): string => substr($reference, -3))->sort()->values()->all())->toBe(['044', '045', '047', '049', '050', '051', '052'])
        ->and(Asset::query()->whereIn('id', $jobs->where('status', MaintenanceStatus::Ongoing)->pluck('asset_id'))->pluck('status')->unique()->all())->toBe([AssetStatus::InMaintenance]);

    // Signalements : 3 nouveaux, 1 pris en charge, 1 traité, 1 rejeté.
    expect(AssetReport::query()->where('status', ReportStatus::New)->count())->toBe(3)
        ->and(AssetReport::query()->where('status', ReportStatus::InProgress)->count())->toBe(1)
        ->and(AssetReport::query()->where('status', ReportStatus::Handled)->count())->toBe(1)
        ->and(AssetReport::query()->where('status', ReportStatus::Rejected)->count())->toBe(1);

    // Les biens imposés par les autres démonstrations ne bougent pas.
    // PAT-000034 est sorti par la démonstration du lot 10 (cas A5) : aucun prêt, intervention ni signalement.
    $pat34 = Asset::query()->where('number', 'PAT-000034')->sole();
    expect($pat34->status)->toBe(AssetStatus::Retired)
        ->and(LoanItem::query()->where('asset_id', $pat34->id)->exists())->toBeFalse()
        ->and(MaintenanceJob::query()->where('asset_id', $pat34->id)->exists())->toBeFalse();

    $counts = [Loan::query()->count(), MaintenanceJob::query()->count(), AssetReport::query()->count(), LoanItem::query()->count()];
    $this->seed(OperationsDemoSeeder::class);

    expect([Loan::query()->count(), MaintenanceJob::query()->count(), AssetReport::query()->count(), LoanItem::query()->count()])->toBe($counts)
        ->and(auth()->user())->toBeNull();
});
