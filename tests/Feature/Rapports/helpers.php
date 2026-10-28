<?php

use App\Actions\Loans\CreateLoan;
use App\Enums\AssetStatus;
use App\Enums\BorrowerType;
use App\Enums\Depreciability;
use App\Enums\FiscalYearStatus;
use App\Enums\InventoryScopeType;
use App\Enums\InventoryStatus;
use App\Enums\LoanDirection;
use App\Enums\MaintenanceNature;
use App\Enums\MaintenanceStatus;
use App\Enums\ProviderType;
use App\Models\Asset;
use App\Models\AssetCategory;
use App\Models\AssetCondition;
use App\Models\Department;
use App\Models\Inventory;
use App\Models\Loan;
use App\Models\MaintenanceJob;
use App\Models\Site;
use App\Models\User;
use App\Support\DefaultRoles;
use Filament\Facades\Filament;

require_once __DIR__.'/../Amortissements/helpers.php';

/**
 * Décor commun des lots « tableau de bord, alertes, rapports » : un site, deux départements, des biens
 * amortissables, un bien hors service, un prêt en retard, une intervention ancienne et un inventaire proche.
 */
function reportingWorld(): object
{
    Filament::setCurrentPanel(Filament::getPanel('admin'));

    $w = new stdClass;
    $w->manager = User::factory()->role(DefaultRoles::MANAGER)->create(['first_name' => 'Albertine', 'last_name' => 'Okemba']);
    $w->accountant = User::factory()->role(DefaultRoles::ACCOUNTANT)->create();
    $w->viewer = User::factory()->role(DefaultRoles::VIEWER)->create();
    $w->admin = User::factory()->admin()->create();

    test()->actingAs($w->manager);

    fiscalYear(2024, FiscalYearStatus::Closed);
    fiscalYear(2025, FiscalYearStatus::Closed);
    $w->year = fiscalYear((int) now()->format('Y'), FiscalYearStatus::Open);

    $w->siege = Site::factory()->create(['name' => 'Siège']);
    $w->louange = Department::factory()->create(['name' => 'Louange']);
    $w->jeunesse = Department::factory()->create(['name' => 'Jeunesse']);
    $w->bon = AssetCondition::factory()->create(['label' => 'Bon état', 'sort' => 1]);
    $w->materiel = AssetCategory::factory()->create(['name' => 'Matériel informatique', 'prefix' => 'INF', 'depreciable' => Depreciability::Yes, 'default_duration_years' => 3]);
    $w->mobilier = AssetCategory::factory()->create(['name' => 'Mobilier', 'prefix' => 'MOB', 'depreciable' => Depreciability::No]);

    $w->ordinateurLouange = createDepreciableAsset(['designation' => 'Ordinateur Louange', 'site_id' => $w->siege->id, 'department_id' => $w->louange->id, 'asset_condition_id' => $w->bon->id], $w->materiel);
    $w->ordinateurJeunesse = createDepreciableAsset(['designation' => '=1+1 Ordinateur Jeunesse', 'site_id' => $w->siege->id, 'department_id' => $w->jeunesse->id, 'asset_condition_id' => $w->bon->id, 'acquisition_cost' => 600000], $w->materiel);

    $plain = fn (string $designation, int $department, int $cost, AssetStatus $status = AssetStatus::InService): Asset => Asset::factory()->create([
        'designation' => $designation,
        'asset_category_id' => $w->mobilier->id,
        'site_id' => $w->siege->id,
        'department_id' => $department,
        'asset_condition_id' => $w->bon->id,
        'status' => $status,
        'acquisition_cost' => $cost,
    ])->fresh();

    $w->chaise = $plain('Chaise Louange', $w->louange->id, 20000);
    $w->groupe = $plain('Groupe électrogène Jeunesse', $w->jeunesse->id, 4800000, AssetStatus::OutOfService);
    $w->sorti = Asset::factory()->disposed()->create(['designation' => 'Table sortie', 'asset_category_id' => $w->mobilier->id, 'site_id' => $w->siege->id, 'department_id' => $w->louange->id, 'asset_condition_id' => $w->bon->id, 'acquisition_cost' => 90000])->fresh();

    $w->headLouange = User::factory()->role(DefaultRoles::DEPARTMENT_HEAD)->create(['first_name' => 'Emmanuel', 'last_name' => 'Mbemba']);
    $w->headLouange->departments()->attach($w->louange);
    $w->headJeunesse = User::factory()->role(DefaultRoles::DEPARTMENT_HEAD)->create(['first_name' => 'David', 'last_name' => 'Massamba']);
    $w->headJeunesse->departments()->attach($w->jeunesse);
    $w->limitedManager = User::factory()->role(DefaultRoles::MANAGER)->create(['first_name' => 'Gestionnaire', 'last_name' => 'Louange']);
    $w->limitedManager->departments()->attach($w->louange);

    return $w;
}

/**
 * Prêt en cours d'un bien, avec une échéance relative à aujourd'hui.
 */
function loanOf(object $w, Asset $asset, int $dueInDays, string $borrower = 'Chorale Béthel'): Loan
{
    return app(CreateLoan::class)([
        'direction' => LoanDirection::Out,
        'borrower_type' => BorrowerType::External,
        'borrower_name' => $borrower,
        'out_on' => today()->subDays(10)->toDateString(),
        'due_on' => today()->addDays($dueInDays)->toDateString(),
        'responsible_id' => $w->manager->id,
    ], [$asset->id]);
}

function openJob(Asset $asset, int $startedDaysAgo, string $reference = 'MNT-2026-001'): MaintenanceJob
{
    return MaintenanceJob::query()->create([
        'reference' => $reference,
        'asset_id' => $asset->id,
        'nature' => MaintenanceNature::Repair,
        'reason' => 'Révision',
        'starts_on' => today()->subDays($startedDaysAgo)->toDateString(),
        'provider_type' => ProviderType::Provider,
        'provider_name' => 'Garage Mavoungou',
        'status' => MaintenanceStatus::Ongoing,
    ]);
}

function plannedInventory(object $w, int $startsInDays, string $reference = 'INV-2026-010'): Inventory
{
    $inventory = new Inventory;
    $inventory->forceFill([
        'reference' => $reference,
        'scope_type' => InventoryScopeType::Site,
        'scope_id' => $w->siege->id,
        'site_id' => $w->siege->id,
        'perimeter_site_id' => $w->siege->id,
        'perimeter_department_id' => null,
        'starts_on' => today()->addDays($startsInDays)->toDateString(),
        'manager_id' => $w->manager->id,
        'status' => InventoryStatus::Planned,
    ])->save();

    return $inventory;
}
