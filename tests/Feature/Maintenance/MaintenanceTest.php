<?php

use App\Actions\Assets\UpdateAsset;
use App\Actions\Loans\CreateLoan;
use App\Actions\Maintenance\CloseMaintenanceJob;
use App\Actions\Maintenance\OpenMaintenanceJob;
use App\Actions\Maintenance\StartMaintenanceJob;
use App\Actions\Maintenance\UpdateMaintenanceJob;
use App\Enums\AssetStatus;
use App\Enums\BorrowerType;
use App\Enums\LoanDirection;
use App\Enums\MaintenanceNature;
use App\Enums\MaintenanceResult;
use App\Enums\MaintenanceStatus;
use App\Enums\ProviderType;
use App\Exceptions\ConcurrentModificationException;
use App\Models\Activity;
use App\Models\Asset;
use App\Models\AssetCondition;
use App\Models\MaintenanceJob;
use App\Models\User;
use App\Support\DefaultRoles;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;
use Tests\Support\OperationsWorld;

beforeEach(function () {
    $this->w = OperationsWorld::create();
    $this->actingAs($this->w->manager);
});

function jobData(Asset $asset, array $overrides = []): array
{
    return [
        'asset_id' => $asset->id,
        'nature' => MaintenanceNature::Repair,
        'reason' => 'Embrayage qui patine',
        'starts_on' => today()->subDays(3)->toDateString(),
        'provider_type' => ProviderType::Provider,
        'provider_name' => 'Garage Mavoungou',
        'cost' => 650000,
        'cost_reference' => 'Devis GM-D-2026-118',
        'put_in_maintenance' => true,
        ...$overrides,
    ];
}

function closeData(array $overrides = []): array
{
    return [
        'result' => MaintenanceResult::Repaired,
        'condition_after_id' => test()->w->moyen->id,
        'status_after' => AssetStatus::InService,
        'ends_on' => today()->toDateString(),
        ...$overrides,
    ];
}

it('ouvre une intervention en cours et passe le bien En maintenance sur demande (RG-60, RG-61)', function () {
    $asset = $this->w->asset();

    $job = app(OpenMaintenanceJob::class)(jobData($asset));

    expect($job->reference)->toMatch('/^MNT-\d{4}-001$/')
        ->and($job->status)->toBe(MaintenanceStatus::Ongoing)
        ->and($job->nature)->toBe(MaintenanceNature::Repair)
        ->and($job->cost)->toBe(650000)
        ->and($asset->fresh()->status)->toBe(AssetStatus::InMaintenance);
});

it('laisse le statut du bien intact si l\'option n\'est pas cochée (RG-61)', function () {
    $asset = $this->w->asset();

    $job = app(OpenMaintenanceJob::class)(jobData($asset, ['put_in_maintenance' => false]));

    expect($job->status)->toBe(MaintenanceStatus::Ongoing)
        ->and($asset->fresh()->status)->toBe(AssetStatus::InService);
});

it('planifie une intervention qui commence plus tard et ne touche pas le bien', function () {
    $asset = $this->w->asset();

    $job = app(OpenMaintenanceJob::class)(jobData($asset, ['starts_on' => today()->addDays(8)->toDateString()]));

    expect($job->status)->toBe(MaintenanceStatus::Planned)
        ->and($asset->fresh()->status)->toBe(AssetStatus::InService);

    $started = app(StartMaintenanceJob::class)($job, putInMaintenance: true);

    expect($started->status)->toBe(MaintenanceStatus::Ongoing)
        ->and($started->starts_on->toDateString())->toBe(today()->toDateString())
        ->and($asset->fresh()->status)->toBe(AssetStatus::InMaintenance)
        ->and(fn () => app(StartMaintenanceJob::class)($started))->toThrow(ValidationException::class);
});

it('refuse une seconde intervention ouverte sur le même bien, un bien sorti et un bien emprunté passé En maintenance', function () {
    $asset = $this->w->asset();
    app(OpenMaintenanceJob::class)(jobData($asset));

    expect(fn () => app(OpenMaintenanceJob::class)(jobData($asset)))->toThrow(ValidationException::class);

    $disposed = Asset::factory()->disposed()->create(['site_id' => $this->w->site->id, 'department_id' => $this->w->louange->id, 'asset_condition_id' => $this->w->bon->id]);

    expect(fn () => app(OpenMaintenanceJob::class)(jobData($disposed)))->toThrow(ValidationException::class);

    $loaned = $this->w->asset();
    app(CreateLoan::class)([
        'direction' => LoanDirection::Out, 'borrower_type' => BorrowerType::External, 'borrower_name' => 'Famille',
        'out_on' => today()->toDateString(), 'due_on' => today()->addDays(3)->toDateString(), 'responsible_id' => $this->w->manager->id,
    ], [$loaned->id]);

    expect(fn () => app(OpenMaintenanceJob::class)(jobData($loaned)))->toThrow(ValidationException::class)
        ->and($loaned->fresh()->status)->toBe(AssetStatus::OnLoan)
        ->and(MaintenanceJob::query()->where('asset_id', $loaned->id)->count())->toBe(0)
        ->and(app(OpenMaintenanceJob::class)(jobData($loaned, ['put_in_maintenance' => false]))->exists)->toBeTrue()
        ->and($loaned->fresh()->status)->toBe(AssetStatus::OnLoan);
});

it('exige nature, motif, intervenant et date de début, et un coût entier positif', function () {
    $asset = $this->w->asset();

    expect(fn () => app(OpenMaintenanceJob::class)(jobData($asset, ['reason' => ' '])))->toThrow(ValidationException::class);

    foreach ([['nature' => null], ['provider_type' => null], ['starts_on' => null], ['cost' => -5], ['cost' => 'abc'], ['cost' => 12.5], ['asset_id' => null]] as $bad) {
        expect(fn () => app(OpenMaintenanceJob::class)(jobData($asset, $bad)))->toThrow(ValidationException::class);
    }

    expect(MaintenanceJob::query()->count())->toBe(0)
        ->and(app(OpenMaintenanceJob::class)(jobData($asset, ['cost' => '650000']))->cost)->toBe(650000);
});

it('clôture l\'intervention : résultat, nouvel état et nouveau statut du bien (RG-61)', function () {
    $asset = $this->w->asset();
    $job = app(OpenMaintenanceJob::class)(jobData($asset));

    $closed = app(CloseMaintenanceJob::class)($job, closeData(['status_after' => AssetStatus::InStock, 'cost' => 700000, 'notes' => 'Kit embrayage posé.']));

    expect($closed->status)->toBe(MaintenanceStatus::Done)
        ->and($closed->result)->toBe(MaintenanceResult::Repaired)
        ->and($closed->ends_on->toDateString())->toBe(today()->toDateString())
        ->and($closed->cost)->toBe(700000)
        ->and($closed->condition_after_id)->toBe($this->w->moyen->id)
        ->and($closed->status_after)->toBe(AssetStatus::InStock)
        ->and($asset->fresh()->status)->toBe(AssetStatus::InStock)
        ->and($asset->fresh()->asset_condition_id)->toBe($this->w->moyen->id);
});

it('limite le nouveau statut aux statuts manuels (RG-12, RG-61)', function (AssetStatus $status) {
    $asset = $this->w->asset();
    $job = app(OpenMaintenanceJob::class)(jobData($asset));

    expect(fn () => app(CloseMaintenanceJob::class)($job, closeData(['status_after' => $status])))->toThrow(ValidationException::class)
        ->and($asset->fresh()->status)->toBe(AssetStatus::InMaintenance)
        ->and($job->fresh()->status)->toBe(MaintenanceStatus::Ongoing);
})->with([AssetStatus::InMaintenance, AssetStatus::OnLoan, AssetStatus::Retired, AssetStatus::TransferredOut]);

it('exige résultat, nouvel état actif, nouveau statut et dates cohérentes à la clôture', function () {
    $asset = $this->w->asset();
    $job = app(OpenMaintenanceJob::class)(jobData($asset));
    $inactive = AssetCondition::factory()->inactive()->create();

    foreach ([['result' => null], ['condition_after_id' => null], ['condition_after_id' => $inactive->id], ['status_after' => null], ['ends_on' => today()->subDays(30)->toDateString()], ['ends_on' => today()->addDay()->toDateString()], ['ends_on' => null], ['cost' => -1]] as $bad) {
        expect(fn () => app(CloseMaintenanceJob::class)($job, closeData($bad)))->toThrow(ValidationException::class);
    }

    expect($job->fresh()->status)->toBe(MaintenanceStatus::Ongoing);
});

it('refuse de clôturer deux fois, ou une intervention planifiée non démarrée', function () {
    $asset = $this->w->asset();
    $job = app(OpenMaintenanceJob::class)(jobData($asset));
    app(CloseMaintenanceJob::class)($job, closeData());

    expect(fn () => app(CloseMaintenanceJob::class)($job, closeData()))->toThrow(ValidationException::class);

    $planned = app(OpenMaintenanceJob::class)(jobData($asset, ['starts_on' => today()->addDays(4)->toDateString()]));

    expect(fn () => app(CloseMaintenanceJob::class)($planned, closeData()))->toThrow(ValidationException::class);
});

it('une intervention terminée ne se modifie plus et rien ne se supprime', function () {
    $job = app(OpenMaintenanceJob::class)(jobData($this->w->asset()));
    $closed = app(CloseMaintenanceJob::class)($job, closeData());

    expect(fn () => $closed->update(['reason' => 'x']))->toThrow(LogicException::class)
        ->and(fn () => $closed->delete())->toThrow(LogicException::class)
        ->and(fn () => app(UpdateMaintenanceJob::class)($closed, jobData($this->w->asset())))->toThrow(ValidationException::class)
        ->and($this->w->manager->can('update', $closed))->toBeFalse()
        ->and($this->w->manager->can('delete', $closed))->toBeFalse();
});

it('un bien emprunté pendant l\'intervention garde son statut Emprunté à la clôture', function () {
    $asset = $this->w->asset();
    $job = app(OpenMaintenanceJob::class)(jobData($asset, ['put_in_maintenance' => false]));

    app(CreateLoan::class)([
        'direction' => LoanDirection::Out, 'borrower_type' => BorrowerType::External, 'borrower_name' => 'Famille',
        'out_on' => today()->toDateString(), 'due_on' => today()->addDays(3)->toDateString(), 'responsible_id' => $this->w->manager->id,
    ], [$asset->id]);

    $closed = app(CloseMaintenanceJob::class)($job, closeData(['status_after' => null]));

    expect($closed->status_after)->toBeNull()
        ->and($asset->fresh()->status)->toBe(AssetStatus::OnLoan)
        ->and($asset->fresh()->asset_condition_id)->toBe($this->w->moyen->id);
});

it('le coût de l\'intervention n\'entre pas dans la valeur amortissable (RG-62)', function () {
    $asset = $this->w->asset(['acquisition_cost' => 540000]);
    $before = $asset->only(['acquisition_cost', 'is_depreciable', 'duration_years', 'residual_value', 'depreciation_method']);
    $lines = DB::table('depreciation_lines')->count();

    $job = app(OpenMaintenanceJob::class)(jobData($asset, ['cost' => 650000]));
    app(CloseMaintenanceJob::class)($job, closeData(['cost' => 700000]));

    expect($asset->fresh()->only(['acquisition_cost', 'is_depreciable', 'duration_years', 'residual_value', 'depreciation_method']))->toBe($before)
        ->and($asset->fresh()->acquisition_cost)->toBe(540000)
        ->and(DB::table('depreciation_lines')->count())->toBe($lines)
        ->and($job->fresh()->cost)->toBe(700000);
});

it('trace l\'ouverture et la clôture au journal avec avant et après (RG-70)', function () {
    $asset = $this->w->asset();
    $job = app(OpenMaintenanceJob::class)(jobData($asset));
    app(CloseMaintenanceJob::class)($job, closeData(['status_after' => AssetStatus::OutOfService, 'result' => MaintenanceResult::Irreparable]));

    $opened = Activity::query()->where('log_name', 'maintenance')->where('event', 'opened')->sole();
    $closed = Activity::query()->where('log_name', 'maintenance')->where('event', 'closed')->sole();

    expect($opened->subject_id)->toBe($asset->id)
        ->and($opened->causer_id)->toBe($this->w->manager->id)
        ->and($opened->properties['old']['status'])->toBe('En service')
        ->and($opened->properties['attributes']['status'])->toBe('En maintenance')
        ->and($closed->properties['old']['status'])->toBe('En maintenance')
        ->and($closed->properties['old']['condition'])->toBe('Bon état')
        ->and($closed->properties['attributes']['status'])->toBe('Hors service')
        ->and($closed->properties['attributes']['condition'])->toBe('État moyen')
        ->and($closed->properties['attributes']['result'])->toBe('Irréparable');
});

it('numérote les interventions de façon unique, par année de début (MNT)', function () {
    $first = app(OpenMaintenanceJob::class)(jobData($this->w->asset(), ['starts_on' => today()->toDateString()]));
    $second = app(OpenMaintenanceJob::class)(jobData($this->w->asset(), ['starts_on' => today()->toDateString()]));
    $next = app(OpenMaintenanceJob::class)(jobData($this->w->asset(), ['starts_on' => today()->addYear()->startOfYear()->toDateString()]));

    expect($first->reference)->toBe('MNT-'.today()->year.'-001')
        ->and($second->reference)->toBe('MNT-'.today()->year.'-002')
        ->and($next->reference)->toBe('MNT-'.(today()->year + 1).'-001')
        ->and(MaintenanceJob::query()->pluck('reference')->unique())->toHaveCount(3);
});

it('contrôle la version du bien à l\'ouverture, au démarrage et à la clôture (RG-86)', function () {
    $asset = $this->w->asset();
    $opened = $asset->lock_version;

    app(UpdateAsset::class)($asset, [...$asset->only((new Asset)->getFillable()), 'notes' => 'Modifié entre-temps']);

    expect(fn () => app(OpenMaintenanceJob::class)(jobData($asset), $opened))->toThrow(ConcurrentModificationException::class)
        ->and(MaintenanceJob::query()->count())->toBe(0);

    $job = app(OpenMaintenanceJob::class)(jobData($asset), $asset->fresh()->lock_version);
    $closing = $asset->fresh()->lock_version;

    app(UpdateAsset::class)($asset->fresh(), [...$asset->fresh()->only((new Asset)->getFillable()), 'notes' => 'Encore modifié']);

    expect(fn () => app(CloseMaintenanceJob::class)($job, closeData(), $closing))->toThrow(ConcurrentModificationException::class)
        ->and($job->fresh()->status)->toBe(MaintenanceStatus::Ongoing)
        ->and($asset->fresh()->status)->toBe(AssetStatus::InMaintenance);
});

it('modifie une intervention ouverte avec contrôle de version et journal (RG-86)', function () {
    $job = app(OpenMaintenanceJob::class)(jobData($this->w->asset()));
    $opened = $job->fresh()->lock_version;

    $updated = app(UpdateMaintenanceJob::class)($job, jobData($job->asset, ['reason' => 'Embrayage et boîte', 'cost' => 720000]), $opened);

    expect($updated->reason)->toBe('Embrayage et boîte')
        ->and($updated->cost)->toBe(720000)
        ->and($updated->lock_version)->toBeGreaterThan($opened)
        ->and(Activity::query()->where('log_name', 'maintenance')->where('event', 'updated')->sole()->properties['old']['cost'])->toBe(650000)
        ->and(fn () => app(UpdateMaintenanceJob::class)($job, jobData($job->asset, ['cost' => 1]), $opened))->toThrow(ConcurrentModificationException::class)
        ->and($job->fresh()->cost)->toBe(720000);
});

it('masque les interventions des biens hors du périmètre et refuse de les clôturer (RG-81)', function () {
    $mine = $this->w->asset();
    $other = $this->w->asset(['department_id' => $this->w->jeunesse->id]);
    $otherJob = app(OpenMaintenanceJob::class)(jobData($other));
    app(OpenMaintenanceJob::class)(jobData($mine));

    $restricted = $this->w->restrictedManager($this->w->louange);
    $this->actingAs($restricted);

    expect(MaintenanceJob::query()->count())->toBe(1)
        ->and(MaintenanceJob::query()->find($otherJob->id))->toBeNull()
        ->and(fn () => app(CloseMaintenanceJob::class)($otherJob, closeData()))->toThrow(ValidationException::class)
        ->and(fn () => app(OpenMaintenanceJob::class)(jobData($other)))->toThrow(ValidationException::class)
        ->and($otherJob->fresh()->status)->toBe(MaintenanceStatus::Ongoing);
});

it('applique les droits de la matrice à la maintenance', function () {
    $head = $this->w->head($this->w->louange);
    $accountant = User::factory()->role(DefaultRoles::ACCOUNTANT)->create();
    $job = app(OpenMaintenanceJob::class)(jobData($this->w->asset()));

    $this->actingAs($head);

    expect($head->can('viewAny', MaintenanceJob::class))->toBeTrue()
        ->and($head->can('create', MaintenanceJob::class))->toBeFalse()
        ->and(fn () => app(OpenMaintenanceJob::class)(jobData($this->w->asset())))->toThrow(AuthorizationException::class)
        ->and(fn () => app(CloseMaintenanceJob::class)($job, closeData()))->toThrow(AuthorizationException::class)
        ->and(fn () => app(StartMaintenanceJob::class)($job))->toThrow(AuthorizationException::class)
        ->and(fn () => app(UpdateMaintenanceJob::class)($job, jobData($job->asset)))->toThrow(AuthorizationException::class)
        ->and($accountant->can('viewAny', MaintenanceJob::class))->toBeTrue()
        ->and($accountant->can('create', MaintenanceJob::class))->toBeFalse();
});
