<?php

use App\Actions\Alerts\RefreshAlerts;
use App\Actions\Disposals\RecordDisposal;
use App\Actions\Loans\CreateLoan;
use App\Enums\BorrowerType;
use App\Enums\LoanDirection;
use App\Filament\Pages\Alerts;
use App\Filament\Pages\Dashboard;
use App\Filament\Pages\Reports;
use App\Models\Alert;
use App\Models\Asset;
use App\Models\DepreciationLine;
use App\Models\DepreciationSchedule;
use App\Models\Holder;
use App\Models\User;
use App\Services\Alerts\AlertPresenter;
use App\Services\DepreciationRegister;
use App\Services\Reporting\DashboardData;
use App\Services\Reporting\ReportBuilder;
use App\Support\Money;
use App\Support\Reports\ReportCatalog;
use App\Support\Reports\ReportData;
use App\Support\Reports\ReportFilters;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

use function Pest\Livewire\livewire;

require_once __DIR__.'/helpers.php';

beforeEach(function () {
    $this->w = reportingWorld();
    $this->year = app(ReportBuilder::class)->currentYear();
});

function yearLine(Asset $asset, int $year): DepreciationLine
{
    return DepreciationSchedule::query()->where('asset_id', $asset->id)->where('is_current', true)->firstOrFail()
        ->lines()->where('fiscal_year', $year)->firstOrFail();
}

function dispose(Asset $asset, string $on): void
{
    $asset = $asset->fresh();
    app(RecordDisposal::class)($asset, ['disposed_on' => $on, 'reason' => 'retirement', 'decided_by' => 'Conseil'], $asset->number);
}

function built(string $key, array $filters = [], ?User $user = null): ReportData
{
    return app(ReportBuilder::class)->build(ReportCatalog::find($key), ReportFilters::fromArray($filters), $user ?? test()->w->manager);
}

it('B1 : un bien sorti dans l\'exercice garde sa dotation mais sort des cumuls, de la VNC et des totalement amortis (RG-52, A5)', function () {
    dispose($this->w->ordinateurJeunesse, today()->subMonths(2)->toDateString());

    // Valeurs attendues lues sur les lignes de plan enregistrées, sans passer par les services testés.
    $kept = yearLine($this->w->ordinateurLouange, $this->year);
    $disposed = yearLine($this->w->ordinateurJeunesse, $this->year);
    $expectedNet = $kept->net_book_value;
    $expectedCharges = $kept->amount + $disposed->amount;

    $overview = app(DashboardData::class)->overview($this->w->manager);

    expect($disposed->amount)->toBeGreaterThan(0)
        ->and($overview['values']['net'])->toBe($expectedNet)
        ->and($overview['values']['charges'])->toBe($expectedCharges)
        ->and(array_sum(array_column($overview['sites'], 'net')))->toBe($expectedNet)
        ->and(array_sum(array_column(built('net_book_value')->rows, 4)))->toBe($expectedNet)
        ->and(array_sum(array_column(built('by_site')->rows, 3)))->toBe($expectedNet)
        ->and(collect(built('depreciation_accumulated')->rows)->pluck(0)->all())->toBe([$this->w->ordinateurLouange->number])
        ->and(built('depreciation_charges')->footerRight)->toContain(Money::format($expectedCharges));

    // Tableau des immobilisations : la dotation jusqu'à la sortie figure, sans cumul ni VNC.
    $register = collect(built('depreciation_register')->rows);
    $row = $register->first(fn (array $r) => $r[0] === $this->w->ordinateurJeunesse->number);

    expect($row[5])->toBe($disposed->amount)->and($row[6])->toBeNull()->and($row[7])->toBeNull()
        ->and($register->sum(5))->toBe($expectedCharges);

    // Rapport des sorties : le bien reste, avec ses valeurs figées.
    $disposals = built('disposals');

    expect($disposals->count())->toBe(1)
        ->and($disposals->rows[0][2])->toBe($this->w->ordinateurJeunesse->number)
        ->and($disposals->rows[0][5])->toBe(600000);
});

it('B1 : un bien sorti ne compte pas parmi les totalement amortis, même quand son plan est arrêté (E30 comprise)', function () {
    dispose($this->w->ordinateurJeunesse, today()->subMonths(2)->toDateString());
    $register = app(DepreciationRegister::class);
    $totals = $register->summary($register->query($this->year), $this->year)['totals'];
    $fully = $register->applyTab($register->query($this->year), DepreciationRegister::TAB_FULLY, $this->year)->pluck('assets.number')->all();

    expect($totals['fully'])->toBe(0)
        ->and($totals['count'])->toBe(1)
        ->and($fully)->toBe([])
        ->and(built('fully_depreciated')->rows)->toBe([]);
});

it('I3 : les chiffres attendus sont calculés indépendamment, et un emprunt externe ne compte nulle part', function () {
    $plain = $this->w->chaise->acquisition_cost + $this->w->groupe->acquisition_cost;
    $expectedAcquisition = 540000 + 600000 + $plain;
    $linesNet = yearLine($this->w->ordinateurLouange, $this->year)->net_book_value + yearLine($this->w->ordinateurJeunesse, $this->year)->net_book_value;
    $before = app(DashboardData::class)->overview($this->w->manager);

    app(CreateLoan::class)([
        'direction' => LoanDirection::In, 'borrower_type' => BorrowerType::External, 'borrower_name' => 'Voisin',
        'out_on' => today()->subDays(3)->toDateString(), 'due_on' => today()->addDays(9)->toDateString(),
        'responsible_id' => $this->w->manager->id, 'external_designation' => 'Sono empruntée', 'condition_out_id' => $this->w->bon->id,
    ], []);

    $after = app(DashboardData::class)->overview($this->w->manager);

    expect($after['values']['acquisition'])->toBe($expectedAcquisition)
        ->and($after['values']['net'])->toBe($linesNet)
        ->and($after['counters']['active'])->toBe(4)
        ->and(built('general')->count())->toBe(4)
        ->and(built('general')->footerRight)->toContain(Money::format($expectedAcquisition))
        ->and($after['values'])->toBe($before['values'])
        ->and($after['counters']['total'])->toBe($before['counters']['total']);
});

it('I1 : sans le droit de consulter les biens, le tableau de bord ne montre aucun bien', function () {
    $user = User::factory()->create();
    $user->givePermissionTo('loans.view');
    $this->actingAs($user);
    $overview = app(DashboardData::class)->overview($user);

    expect($overview['values']['acquisition'])->toBeNull()
        ->and($overview['departments'])->toBeNull()
        ->and($overview['sites'])->toBeNull()
        ->and($overview['recent_assets'])->toBeNull()
        ->and($overview['to_handle'])->toBeNull();

    livewire(Dashboard::class)
        ->assertDontSee('Chaise Louange')
        ->assertDontSee('Groupe électrogène Jeunesse')
        ->assertDontSee('acquisition totale');
});

it('I2 : un responsable hors périmètre n\'apparaît ni dans les filtres ni dans l\'en-tête des rapports', function () {
    $secret = Holder::query()->create(['name' => 'Pasteur Secret Jeunesse', 'department_id' => $this->w->jeunesse->id]);
    $visible = Holder::query()->create(['name' => 'Sœur Visible Louange', 'department_id' => $this->w->louange->id]);
    $this->actingAs($this->w->limitedManager);

    $options = livewire(Reports::class)->instance()->options();
    $data = built('general', ['holder_id' => $secret->id], $this->w->limitedManager);

    expect($options['holder'])->toHaveKey($visible->id)->not->toHaveKey($secret->id)
        ->and(implode(' ', $data->filters))->not->toContain('Secret')
        ->and(ReportFilters::fromArray(['holder_id' => $secret->id])->holderId)->toBeNull()
        ->and(ReportFilters::fromArray(['holder_id' => $visible->id])->holderId)->toBe($visible->id);

    // Un responsable qui porte un bien visible reste proposé.
    $this->w->chaise->forceFill(['holder_id' => $secret->id])->saveQuietly();
    expect(ReportFilters::fromArray(['holder_id' => $secret->id])->holderId)->toBe($secret->id);
});

it('M1 : le libellé de fin d\'amortissement suit la date', function () {
    $schedule = DepreciationSchedule::query()->where('asset_id', $this->w->ordinateurLouange->id)->where('is_current', true)->firstOrFail();
    $presenter = fn () => app(AlertPresenter::class)->present(Alert::query()->where('type', 'depreciation_ending')->with('subject')->firstOrFail(), $this->w->manager, true)['detail'];

    app(RefreshAlerts::class)(CarbonImmutable::parse($schedule->ends_on)->subMonth());
    expect($presenter())->toContain('se termine le');

    DB::table('alerts')->where('type', 'depreciation_ending')->update(['event_on' => today()->subDay()->toDateString()]);
    expect($presenter())->toContain('terminé le');
});

it('M2 : l\'écran des alertes ne multiplie pas les requêtes avec le nombre d\'alertes', function () {
    $count = function (int $jobs): int {
        for ($i = 0; $i < $jobs; $i++) {
            openJob(Asset::factory()->create(['asset_category_id' => $this->w->mobilier->id, 'site_id' => $this->w->siege->id, 'department_id' => $this->w->louange->id, 'asset_condition_id' => $this->w->bon->id]), 30, 'MNT-2026-'.str_pad((string) random_int(100, 999), 3, '0').$i);
        }
        app(RefreshAlerts::class)();
        DB::enableQueryLog();
        livewire(Alerts::class)->assertOk();
        $n = count(DB::getQueryLog());
        DB::flushQueryLog();
        DB::disableQueryLog();

        return $n;
    };

    $few = $count(2);
    $many = $count(10);

    expect($many - $few)->toBeLessThan(6);
});

it('M3 : un lancement manuel pendant le passage planifié ne casse pas', function () {
    loanOf($this->w, $this->w->chaise, -3);
    $done = false;
    DB::listen(function ($query) use (&$done) {
        if (! $done && str_contains($query->sql, 'fingerprint') && str_starts_with(strtolower($query->sql), 'select')) {
            $done = true;
            // Un autre processus a déjà créé les alertes entre la lecture et l'écriture.
            DB::table('alerts')->insert(['type' => 'out_of_service', 'fingerprint' => 'out_of_service:'.$this->w->groupe->id, 'subject_type' => Asset::class, 'subject_id' => $this->w->groupe->id, 'raised_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
        }
    });

    $report = app(RefreshAlerts::class)();

    expect($done)->toBeTrue()
        ->and(Alert::query()->where('fingerprint', 'out_of_service:'.$this->w->groupe->id)->count())->toBe(1)
        ->and($report['raised'])->toBe(Alert::query()->count() - 1);
});

it('M4 : les biens sortis ne comptent pas parmi les nouveautés du mois', function () {
    $before = app(DashboardData::class)->overview($this->w->manager)['counters']['new_this_month'];
    Asset::factory()->disposed()->create(['acquired_on' => today()->toDateString(), 'asset_category_id' => $this->w->mobilier->id, 'site_id' => $this->w->siege->id, 'department_id' => $this->w->louange->id, 'asset_condition_id' => $this->w->bon->id]);

    expect(app(DashboardData::class)->overview($this->w->manager)['counters']['new_this_month'])->toBe($before);
});

it('M5 : l\'aperçu ne se recalcule qu\'au clic sur « Appliquer »', function () {
    livewire(Reports::class)
        ->assertSee('Groupe électrogène Jeunesse')
        ->set('filters.department_id', $this->w->louange->id)
        ->assertSee('Groupe électrogène Jeunesse')
        ->call('apply')
        ->assertDontSee('Groupe électrogène Jeunesse')
        ->assertSee('Chaise Louange');
});
