<?php

use App\Enums\FiscalYearStatus;
use App\Filament\Pages\Depreciation\DepreciationRegisterPage;
use App\Filament\Pages\Depreciation\DepreciationRulesPage;
use App\Filament\Resources\Assets\Pages\AssetDepreciationSchedule;
use App\Filament\Resources\Assets\Pages\ViewAsset;
use App\Filament\Resources\FiscalYears\Pages\ManageFiscalYears;
use App\Models\AssetCategory;
use App\Models\Department;
use App\Models\FiscalYear;
use App\Models\User;
use App\Services\Settings;
use App\Support\DefaultRoles;
use App\Support\Money;
use Filament\Facades\Filament;
use Illuminate\Support\Carbon;

use function Pest\Livewire\livewire;

require_once __DIR__.'/helpers.php';

beforeEach(function () {
    Filament::setCurrentPanel(Filament::getPanel('admin'));

    $this->accountant = User::factory()->role(DefaultRoles::ACCOUNTANT)->create();
    $this->manager = User::factory()->role(DefaultRoles::MANAGER)->create();
    $this->actingAs($this->accountant);

    fiscalYear(2024, FiscalYearStatus::Closed);
    fiscalYear(2025, FiscalYearStatus::Closed);
    $this->year2026 = fiscalYear(2026, FiscalYearStatus::Open);

    $this->louange = Department::factory()->create(['name' => 'Louange']);
    $this->jeunesse = Department::factory()->create(['name' => 'Jeunesse']);
    $this->asset = createDepreciableAsset(['department_id' => $this->louange->id]);
    $this->otherAsset = createDepreciableAsset(['department_id' => $this->jeunesse->id, 'acquisition_cost' => 600000]);
});

it('affiche les écrans E30 à E33 au comptable', function () {
    $this->get('/admin/immobilisations')->assertOk()->assertSee('Tableau des immobilisations')->assertSee($this->asset->number);
    $this->get('/admin/biens/'.$this->asset->id.'/amortissement')->assertOk()->assertSee('Tableau d\'amortissement')->assertSee(Money::format(141500))->assertSee('Évolution de la VNC');
    $this->get('/admin/regles-amortissement')->assertOk()->assertSee('Règles par catégorie');
    $this->get('/admin/exercices')->assertOk()->assertSee('2026');
    $this->get('/admin/biens/'.$this->asset->id)->assertOk()->assertSee('Amortissement')->assertSee('Ouvrir le plan d\'amortissement complet', false);
});

it('laisse le gestionnaire consulter sans modifier les règles, valider ni clôturer (RG-83)', function () {
    $this->actingAs($this->manager);

    $this->get('/admin/immobilisations')->assertOk();
    $this->get('/admin/regles-amortissement')->assertOk();

    livewire(AssetDepreciationSchedule::class, ['record' => $this->asset->getKey()])
        ->assertActionHidden('changeParameters')
        ->assertActionHidden('validate')
        ->assertActionVisible('exportPdf');

    livewire(DepreciationRulesPage::class)->assertActionHidden('save');

    livewire(ManageFiscalYears::class)
        ->assertActionHidden('createFiscalYear')
        ->assertTableActionHidden('close', $this->year2026);
});

it('ferme les écrans d\'amortissement au responsable de département', function () {
    $head = User::factory()->role(DefaultRoles::DEPARTMENT_HEAD)->create();
    $head->departments()->attach($this->louange);
    $this->actingAs($head);

    $this->get('/admin/immobilisations')->assertForbidden();
    $this->get('/admin/biens/'.$this->asset->id.'/amortissement')->assertForbidden();
    $this->get('/admin/exercices')->assertForbidden();
    $this->get('/admin/biens/'.$this->asset->id)->assertOk()->assertDontSee('Ouvrir le plan d\'amortissement complet', false);
});

it('limite le tableau des immobilisations et les plans au périmètre (RG-81)', function () {
    $viewer = User::factory()->role(DefaultRoles::VIEWER)->create();
    $viewer->departments()->attach($this->louange);
    $this->actingAs($viewer);

    livewire(DepreciationRegisterPage::class)
        ->assertCanSeeTableRecords([$this->asset])
        ->assertCanNotSeeTableRecords([$this->otherAsset]);

    $this->get('/admin/biens/'.$this->asset->id.'/amortissement')->assertOk();
    $this->get('/admin/biens/'.$this->otherAsset->id.'/amortissement')->assertNotFound();
});

it('modifie les paramètres depuis le plan, avec motif obligatoire (E31, RG-37)', function () {
    livewire(AssetDepreciationSchedule::class, ['record' => $this->asset->getKey()])
        ->callAction('changeParameters', ['duration_years' => 4, 'reason' => ''])
        ->assertHasActionErrors(['reason' => 'required']);

    livewire(AssetDepreciationSchedule::class, ['record' => $this->asset->getKey()])
        ->callAction('changeParameters', ['duration_years' => 4, 'reason' => 'Durée d\'usage constatée plus longue'])
        ->assertHasNoActionErrors()
        ->assertSee(Money::format(98695));

    expect(scheduleLines($this->asset))->toBe(depreciationCase('A6')['attendu']);
});

it('valide le plan depuis son écran (RG-38)', function () {
    livewire(AssetDepreciationSchedule::class, ['record' => $this->asset->getKey()])
        ->callAction('validate')
        ->assertActionHidden('validate');

    expect(currentSchedule($this->asset)->validated_by)->toBe($this->accountant->id);
});

it('exporte le plan en PDF et en Excel', function () {
    Carbon::setTestNow(now()->startOfMinute());

    livewire(AssetDepreciationSchedule::class, ['record' => $this->asset->getKey()])
        ->callAction('exportPdf')
        ->assertFileDownloaded('plan-amortissement-'.$this->asset->number.'-'.now()->format('Ymd-His').'.pdf');

    livewire(AssetDepreciationSchedule::class, ['record' => $this->asset->getKey()])
        ->callAction('exportExcel')
        ->assertFileDownloaded('plan-amortissement-'.$this->asset->number.'-'.now()->format('Ymd-His').'.xlsx');

    Carbon::setTestNow();
});

it('clôture un exercice depuis E33 avec confirmation des points d\'attention (RG-42)', function () {
    Carbon::setTestNow('2026-12-31 18:00:00');

    livewire(ManageFiscalYears::class)
        ->callTableAction('close', $this->year2026, ['acknowledged' => false])
        ->assertHasTableActionErrors(['acknowledged']);

    expect($this->year2026->fresh()->status)->toBe(FiscalYearStatus::Open);

    livewire(ManageFiscalYears::class)
        ->callTableAction('close', $this->year2026, ['acknowledged' => true])
        ->assertHasNoTableActionErrors();

    expect($this->year2026->fresh()->status)->toBe(FiscalYearStatus::Closed)
        ->and(FiscalYear::query()->where('year', 2027)->sole()->status)->toBe(FiscalYearStatus::Open);

    Carbon::setTestNow();
});

it('crée un exercice préparé depuis E33', function () {
    livewire(ManageFiscalYears::class)
        ->callAction('createFiscalYear', ['year' => 2027])
        ->assertHasNoActionErrors();

    expect(FiscalYear::query()->where('year', 2027)->sole()->status)->toBe(FiscalYearStatus::Prepared);
});

it('enregistre les règles générales et les règles par catégorie (E32)', function () {
    livewire(DepreciationRulesPage::class)
        ->fillForm([Settings::CAPITALIZATION_THRESHOLD => 150000, Settings::DECLINING_ENABLED => true, Settings::DECLINING_COEFFICIENT_3_4 => '1,5', Settings::DECLINING_COEFFICIENT_5_6 => '2', Settings::DECLINING_COEFFICIENT_OVER_6 => '2,5'])
        ->callAction('save');

    $settings = app(Settings::class);

    expect($settings->int(Settings::CAPITALIZATION_THRESHOLD))->toBe(150000)
        ->and($settings->bool(Settings::DECLINING_ENABLED))->toBeTrue();

    $category = AssetCategory::query()->where('prefix', 'INF')->sole();

    livewire(DepreciationRulesPage::class)
        ->call('updateTableColumnState', 'default_duration_years', (string) $category->getKey(), '4');

    expect($category->fresh()->default_duration_years)->toBe(4)
        ->and($this->asset->fresh()->duration_years)->toBe(3);
});

it('montre les compteurs et l\'onglet Amortissement sur la fiche (E06)', function () {
    livewire(ViewAsset::class, ['record' => $this->asset->getKey()])
        ->assertSee('Amort. cumulés au 31/12/2026')
        ->assertSee(Money::format(501500))
        ->assertSee(Money::format(38500));
});
