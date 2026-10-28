<?php

use App\Actions\Alerts\RefreshAlerts;
use App\Filament\Pages\Dashboard;
use App\Filament\Pages\DepartmentSpace;
use App\Models\User;
use App\Services\Reporting\DashboardData;
use App\Services\Reporting\PatrimonyFigures;
use App\Services\Reporting\ReportBuilder;
use App\Support\Money;
use App\Support\Reports\ReportCatalog;
use App\Support\Reports\ReportData;
use App\Support\Reports\ReportFilters;

use function Pest\Livewire\livewire;

require_once __DIR__.'/../Rapports/helpers.php';

beforeEach(function () {
    $this->w = reportingWorld();
    loanOf($this->w, $this->w->chaise, -3);
    openJob($this->w->groupe, 20);
});

function buildReport(string $key, ?User $user = null, array $filters = []): ReportData
{
    return app(ReportBuilder::class)->build(ReportCatalog::find($key), ReportFilters::fromArray($filters), $user);
}

it('affiche sur le tableau de bord les mêmes chiffres que les rapports (une seule source)', function () {
    $overview = app(DashboardData::class)->overview($this->w->manager);
    $year = app(ReportBuilder::class)->currentYear();

    $general = buildReport('general');
    $register = buildReport('depreciation_register');
    $charges = buildReport('depreciation_charges');
    $byDepartment = buildReport('by_department');
    $bySite = buildReport('by_site');

    // Valeur d'acquisition : biens au patrimoine (le bien sorti n'est pas compté).
    expect($overview['values']['acquisition'])->toBe(540000 + 600000 + 20000 + 4800000)
        ->and($general->footerRight)->toBe('Total '.Money::format($overview['values']['acquisition']).' FCFA')
        ->and($general->count())->toBe($overview['counters']['active'])
        ->and($byDepartment->footerRight)->toBe($general->footerRight)
        ->and(array_sum(array_column($byDepartment->rows, 2)))->toBe($overview['values']['acquisition'])
        // Valeur nette comptable et dotations de l'exercice : mêmes totaux que le tableau des immobilisations.
        ->and($register->footerRight)->toBe('VNC totale '.Money::format($overview['values']['net']).' FCFA')
        ->and($charges->footerRight)->toBe('Dotations '.$year.' : '.Money::format($overview['values']['charges']).' FCFA')
        ->and(array_sum(array_column($register->rows, 7)))->toBe($overview['values']['net'])
        ->and(array_sum(array_column($register->rows, 5)))->toBe($overview['values']['charges'])
        ->and(array_sum(array_column($bySite->rows, 3)))->toBe($overview['values']['net'])
        ->and(array_sum(array_column($overview['sites'], 'count')))->toBe($overview['counters']['active'])
        // Total tous statuts = biens au patrimoine + biens sortis.
        ->and($overview['counters']['total'])->toBe($overview['counters']['active'] + 1);
});

it('compte les bureaux par statut comme la liste des biens', function () {
    $overview = app(DashboardData::class)->overview($this->w->manager);

    expect($overview['counters']['statuses']['out_of_service'])->toBe(1)
        ->and($overview['counters']['statuses']['retired'])->toBe(1)
        ->and($overview['counters']['open_jobs'])->toBe(1)
        ->and($overview['counters']['overdue_loans'])->toBe(1);
});

it('limite tous les chiffres au périmètre du responsable de département (RG-81)', function () {
    $this->actingAs($this->w->headLouange);
    $overview = app(DashboardData::class)->overview($this->w->headLouange);

    // Louange : ordinateur 540 000 + chaise 20 000 ; le bien sorti compte dans le total tous statuts.
    expect($overview['values']['acquisition'])->toBe(560000)
        ->and($overview['counters']['active'])->toBe(2)
        ->and($overview['counters']['total'])->toBe(3)
        ->and(array_column($overview['departments']['items'], 'name'))->toBe(['Louange'])
        ->and($overview['counters']['statuses']['out_of_service'])->toBe(0)
        ->and($overview['counters']['open_jobs'])->toBe(0)
        ->and($overview['counters']['overdue_loans'])->toBe(1)
        ->and(collect($overview['to_handle'])->pluck('designation')->all())->toBe([])
        ->and(collect($overview['recent_assets'])->pluck('department_id')->unique()->all())->toBe([$this->w->louange->id]);

    // Le responsable limité qui a le droit d'exporter ne sort pas non plus de son périmètre.
    $this->actingAs($this->w->limitedManager);
    $general = buildReport('general', $this->w->limitedManager);
    $loans = buildReport('loans', $this->w->limitedManager);

    expect(collect($general->rows)->pluck(1)->sort()->values()->all())->toBe(['Chaise Louange', 'Ordinateur Louange'])
        ->and($general->footerRight)->toBe('Total '.Money::format(560000).' FCFA')
        ->and($loans->count())->toBe(1);
});

it('ne laisse pas un filtre forcé sortir du périmètre', function () {
    $this->actingAs($this->w->limitedManager);

    $forced = buildReport('general', $this->w->limitedManager, ['department_id' => $this->w->jeunesse->id]);
    $site = buildReport('by_site', $this->w->limitedManager);

    expect(collect($forced->rows)->pluck(1)->join(' '))->not->toContain('Jeunesse')
        ->and($forced->count())->toBe(2)
        ->and(array_sum(array_column($site->rows, 1)))->toBe(2);
});

it('affiche le tableau de bord (E02) au gestionnaire, sans les valeurs comptables pour qui ne peut pas les voir', function () {
    $net = Money::format(app(DashboardData::class)->overview($this->w->manager)['values']['net']);

    livewire(Dashboard::class)
        ->assertSee('Bonjour')
        ->assertSee('Albertine')
        ->assertSee('acquisition totale')
        ->assertSee('Valeur nette comptable')
        ->assertSee($net)
        ->assertSee('Biens par département')
        ->assertSee('Biens nécessitant une intervention');

    $noAccounting = User::factory()->create();
    $noAccounting->givePermissionTo(['assets.view']);
    $this->actingAs($noAccounting);

    livewire(Dashboard::class)
        ->assertSee('acquisition totale')
        ->assertDontSee('Valeur nette comptable')
        ->assertDontSee('Transferts récents')
        ->assertDontSee('Dernier inventaire');
});

it('envoie le responsable de département vers son espace (E42) et ne lui montre que son périmètre', function () {
    $this->actingAs($this->w->headLouange);

    livewire(Dashboard::class)->assertRedirect(DepartmentSpace::getUrl());

    $data = app(DashboardData::class)->departmentSpace($this->w->headLouange);

    expect($data['counters']['assigned'])->toBe(2)
        ->and($data['departments']->pluck('name')->all())->toBe(['Louange'])
        ->and($data['assets_total'])->toBe(2);

    livewire(DepartmentSpace::class)
        ->assertSee('Louange')
        ->assertSee('Biens affectés')
        ->assertSee('Chaise Louange')
        ->assertSee('Ordinateur Louange')
        ->assertDontSee('Jeunesse')
        ->assertDontSee('Groupe électrogène')
        ->assertActionExists('report');
});

it('réserve l\'espace du responsable aux profils de département', function () {
    $this->actingAs($this->w->manager);
    $this->get(DepartmentSpace::getUrl())->assertForbidden();

    $this->actingAs($this->w->headJeunesse);
    $this->get(DepartmentSpace::getUrl())->assertOk()->assertSee('Groupe électrogène')->assertDontSee('Chaise Louange');
});

it('affiche dans l\'espace du responsable le signalement qu\'il a fait et ses alertes', function () {
    app(RefreshAlerts::class)();
    $this->actingAs($this->w->headJeunesse);

    $data = app(DashboardData::class)->departmentSpace($this->w->headJeunesse);

    expect($data['unread_alerts'])->toBeGreaterThan(0)
        ->and($data['counters']['out_of_service'])->toBe(1);

    $this->get(DepartmentSpace::getUrl())->assertOk()->assertSee('Alertes');
});

it('fournit une seule source de calcul : PatrimonyFigures sert le tableau de bord et les rapports', function () {
    $figures = app(PatrimonyFigures::class);

    expect($figures->activeTotals()['count'])->toBe(4)
        ->and($figures->activeTotals(new ReportFilters(departmentId: $this->w->louange->id))['acquisition'])->toBe(560000);
});
