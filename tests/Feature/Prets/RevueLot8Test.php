<?php

use App\Actions\Loans\CreateLoan;
use App\Actions\Loans\RecordLoanReturn;
use App\Actions\Maintenance\CloseMaintenanceJob;
use App\Actions\Maintenance\OpenMaintenanceJob;
use App\Actions\Maintenance\UpdateMaintenanceJob;
use App\Enums\AssetStatus;
use App\Enums\BorrowerType;
use App\Enums\LoanDirection;
use App\Enums\LoanStatus;
use App\Enums\MaintenanceNature;
use App\Enums\MaintenanceResult;
use App\Enums\ProviderType;
use App\Filament\Resources\Loans\Pages\CreateLoan as CreateLoanPage;
use App\Filament\Resources\Loans\Pages\ViewLoan;
use App\Filament\Resources\Maintenance\MaintenanceResource;
use App\Filament\Resources\Maintenance\Pages\ViewMaintenanceJob;
use App\Filament\Resources\Maintenance\Widgets\MaintenanceStatsWidget;
use App\Filament\Support\HoldsOperationVersions;
use App\Filament\Support\OperationFields;
use App\Models\Asset;
use App\Models\AssetCondition;
use App\Models\Loan;
use App\Models\MaintenanceJob;
use App\Models\Movement;
use App\Support\DisposalUrl;
use App\Support\OperationLabels;
use Filament\Facades\Filament;
use Illuminate\Validation\ValidationException;
use Tests\Support\OperationsWorld;

use function Pest\Livewire\livewire;

beforeEach(function () {
    Filament::setCurrentPanel(Filament::getPanel('admin'));

    $this->w = OperationsWorld::create();
    $this->actingAs($this->w->manager);
});

function revueLoan(array $ids, array $overrides = []): Loan
{
    return app(CreateLoan::class)([
        'direction' => LoanDirection::Out, 'borrower_type' => BorrowerType::External, 'borrower_name' => 'Famille',
        'out_on' => today()->toDateString(), 'due_on' => today()->addDays(3)->toDateString(),
        'responsible_id' => test()->w->manager->id, ...$overrides,
    ], $ids)->load('items');
}

function revueJob(Asset $asset, array $overrides = []): MaintenanceJob
{
    return app(OpenMaintenanceJob::class)([
        'asset_id' => $asset->id, 'nature' => MaintenanceNature::Repair, 'reason' => 'Panne',
        'starts_on' => today()->toDateString(), 'provider_type' => ProviderType::Internal, 'put_in_maintenance' => true,
        ...$overrides,
    ]);
}

function revueReturn(Loan $loan, array $states): array
{
    $items = [];

    foreach ($loan->items as $index => $item) {
        $items[] = ['id' => $item->id, 'label' => 'x', 'condition_out_id' => $item->condition_out_id, 'condition_back_id' => $states[$index] ?? $item->condition_out_id];
    }

    return ['returned_on' => today()->toDateString(), 'items' => $items];
}

// I1 : RG-86, les versions vues à l'ouverture sont gardées côté serveur.

it('I1 refuse l\'enregistrement quand aucune version n\'a été retenue pour le bien', function () {
    $page = new class
    {
        use HoldsOperationVersions;
    };

    expect(fn () => OperationFields::heldVersions($page, [12]))->toThrow(ValidationException::class)
        ->and(OperationFields::heldVersions((function () use ($page) {
            $page->holdVersions([12 => 3]);

            return $page;
        })(), [12]))->toBe([12 => 3]);
});

it('I1 refuse un prêt quand la fiche a changé depuis la sélection, même si le navigateur envoie une autre version', function () {
    $asset = $this->w->asset();

    $page = livewire(CreateLoanPage::class)->set('data.asset_ids', [$asset->id]);
    $asset->update(['notes' => 'Modifié ailleurs']);

    $page->fillForm([
        'direction' => 'out', 'borrower_type' => 'external', 'borrower_name' => 'X', 'asset_versions' => [$asset->id => $asset->fresh()->lock_version],
        'out_on' => today()->toDateString(), 'due_on' => today()->addDays(3)->toDateString(), 'responsible_id' => $this->w->manager->id,
    ])
        ->call('create')
        ->assertNotified('Enregistrement impossible');

    expect(Loan::query()->count())->toBe(0)
        ->and($asset->fresh()->status)->toBe(AssetStatus::InService);
});

it('I1 empêche le navigateur de modifier les versions retenues (propriété verrouillée)', function () {
    $asset = $this->w->asset();

    expect(fn () => livewire(CreateLoanPage::class)->set('heldVersions', [$asset->id => 999]))->toThrow('Cannot update locked property');
});

it('I1 refuse le retour quand la fiche a changé depuis l\'ouverture, malgré une version falsifiée dans les données', function () {
    $asset = $this->w->asset();
    $loan = revueLoan([$asset->id]);

    $page = livewire(ViewLoan::class, ['record' => $loan->getRouteKey()])->mountAction('return');
    $asset->fresh()->update(['notes' => 'Modifié ailleurs']);

    $page->setActionData([...revueReturn($loan, []), 'versions' => [$asset->id => $asset->fresh()->lock_version]])
        ->callMountedAction()
        ->assertNotified('Retour impossible');

    expect($loan->fresh()->status)->toBe(LoanStatus::Ongoing)
        ->and($asset->fresh()->status)->toBe(AssetStatus::OnLoan);

    // Témoin : sans changement entre-temps, le même retour passe.
    $page = livewire(ViewLoan::class, ['record' => $loan->getRouteKey()])->mountAction('return');
    $page->setActionData(revueReturn($loan, []))->callMountedAction()->assertNotified('Retour enregistré');

    expect($loan->fresh()->status)->toBe(LoanStatus::Returned);
});

it('I1 refuse de démarrer ou de clôturer une intervention dont la fiche a changé depuis l\'ouverture de la fenêtre', function () {
    $planned = revueJob($this->w->asset(), ['starts_on' => today()->addDays(3)->toDateString()]);
    $open = revueJob($other = $this->w->asset());

    $page = livewire(ViewMaintenanceJob::class, ['record' => $planned->getRouteKey()])->mountAction('start');
    $planned->asset->fresh()->update(['notes' => 'Modifié ailleurs']);
    $page->setActionData(['put_in_maintenance' => true, 'asset_version' => $planned->asset->fresh()->lock_version])->callMountedAction()->assertNotified('Démarrage impossible');

    $page = livewire(ViewMaintenanceJob::class, ['record' => $open->getRouteKey()])->mountAction('close');
    $other->fresh()->update(['notes' => 'Modifié ailleurs']);
    $page->setActionData([
        'result' => 'repaired', 'condition_after_id' => $this->w->bon->id, 'status_after' => 'in_service', 'status_applies' => true,
        'ends_on' => today()->toDateString(), 'asset_version' => $other->fresh()->lock_version,
    ])->callMountedAction()->assertNotified('Clôture impossible');

    expect($planned->fresh()->status->value)->toBe('planned')
        ->and($open->fresh()->status->value)->toBe('ongoing');
});

// I2 : libellés français

it('I2 nomme en français les objets liés (Transfert du…, Mise à jour de la fiche…, Prêt, Emprunt, Intervention)', function () {
    $asset = $this->w->asset(['number' => 'PAT-000123']);
    $loan = revueLoan([$asset->id], ['borrower_name' => 'X']);
    $job = revueJob($this->w->asset());
    $movement = (new Movement)->forceFill(['moved_on' => '2026-10-20']);

    expect(OperationLabels::for($movement, asOperation: true))->toBe('Transfert du 20/10/2026')
        ->and(OperationLabels::for($asset, asOperation: true))->toBe('Mise à jour de la fiche PAT-000123')
        ->and(OperationLabels::for($asset))->toBe('Bien PAT-000123')
        ->and(OperationLabels::for($loan))->toStartWith('Prêt PRT-')
        ->and(OperationLabels::for($job))->toStartWith('Intervention MNT-');
});

// M2 : seuls les états actifs au retour

it('M2 refuse un état désactivé au retour', function () {
    $asset = $this->w->asset();
    $loan = revueLoan([$asset->id]);
    $inactive = AssetCondition::factory()->inactive()->create(['sort' => 9]);

    expect(fn () => app(RecordLoanReturn::class)($loan, ['returned_on' => today()->toDateString(), 'items' => [$loan->items->first()->id => ['condition_back_id' => $inactive->id]]]))
        ->toThrow(ValidationException::class)
        ->and($loan->fresh()->status)->toBe(LoanStatus::Ongoing);
});

// M3 : RG-57, aussi moins bon que l'état courant de la fiche

it('M3 met à jour l\'état quand le retour est moins bon que l\'état courant de la fiche, même s\'il vaut l\'état de départ', function () {
    $asset = $this->w->asset(['asset_condition_id' => $this->w->aReparer->id]);
    $job = revueJob($asset, ['put_in_maintenance' => false]);
    $loan = revueLoan([$asset->id]);
    app(CloseMaintenanceJob::class)($job, ['result' => MaintenanceResult::Repaired, 'condition_after_id' => $this->w->neuf->id, 'ends_on' => today()->toDateString()]);

    app(RecordLoanReturn::class)($loan, ['returned_on' => today()->toDateString(), 'items' => [$loan->items->first()->id => ['condition_back_id' => $this->w->moyen->id]]]);

    expect($asset->fresh()->asset_condition_id)->toBe($this->w->moyen->id);
});

// M4 : retour dégradé de plusieurs biens

it('M4 propose une intervention par bien dégradé, exclut les biens sortis et ne redirige que pour un seul bien', function () {
    $a = $this->w->asset();
    $b = $this->w->asset();
    $c = $this->w->asset();
    $loan = revueLoan([$a->id, $b->id, $c->id]);

    $c->forceFill(['disposed_at' => today()->toDateString(), 'status' => AssetStatus::Retired])->save();

    livewire(ViewLoan::class, ['record' => $loan->getRouteKey()])
        ->callAction('return', [...revueReturn($loan, [$this->w->moyen->id, $this->w->aReparer->id, $this->w->aReparer->id]), 'open_maintenance' => true, 'is_out' => true])
        ->assertNoRedirect()
        ->assertNotified('Retour enregistré');

    $loan2 = revueLoan([($d = $this->w->asset())->id, ($e = $this->w->asset())->id]);
    $e->forceFill(['disposed_at' => today()->toDateString(), 'status' => AssetStatus::Retired])->save();

    livewire(ViewLoan::class, ['record' => $loan2->getRouteKey()])
        ->callAction('return', [...revueReturn($loan2, [$this->w->moyen->id, $this->w->moyen->id]), 'open_maintenance' => true, 'is_out' => true])
        ->assertRedirect(MaintenanceResource::getUrl('create').'?'.http_build_query(['bien' => $d->id]));
});

// M6 : coût vidé à la clôture

it('M6 enregistre vide un coût final vidé à la clôture, et garde celui du devis quand le champ est absent (QL8-6)', function () {
    $job = revueJob($this->w->asset(), ['cost' => 650000, 'cost_reference' => 'Devis 1']);
    $closed = app(CloseMaintenanceJob::class)($job, [
        'result' => MaintenanceResult::Repaired, 'condition_after_id' => $this->w->bon->id, 'status_after' => AssetStatus::InService,
        'ends_on' => today()->toDateString(), 'cost' => null, 'cost_reference' => null,
    ]);

    expect($closed->cost)->toBeNull()->and($closed->cost_reference)->toBeNull();

    $kept = app(CloseMaintenanceJob::class)(revueJob($this->w->asset(), ['cost' => 120000]), [
        'result' => MaintenanceResult::Repaired, 'condition_after_id' => $this->w->bon->id, 'status_after' => AssetStatus::InService, 'ends_on' => today()->toDateString(),
    ]);

    expect($kept->cost)->toBe(120000);
});

// M7 : intervention planifiée, date de début passée

it('M7 refuse de passer une intervention planifiée à une date de début passée et invite à la démarrer', function () {
    $job = revueJob($this->w->asset(), ['starts_on' => today()->addDays(5)->toDateString()]);
    $data = ['nature' => MaintenanceNature::Repair, 'reason' => 'Panne', 'provider_type' => ProviderType::Internal, 'starts_on' => today()->subDay()->toDateString()];

    expect(fn () => app(UpdateMaintenanceJob::class)($job, $data))->toThrow(ValidationException::class, 'Démarrer')
        ->and($job->fresh()->starts_on->toDateString())->toBe(today()->addDays(5)->toDateString())
        ->and(app(UpdateMaintenanceJob::class)($job, [...$data, 'starts_on' => today()->addDays(9)->toDateString()])->starts_on->toDateString())->toBe(today()->addDays(9)->toDateString());
});

// M8 : route photo

it('M8 répond 404 et non 500 à un identifiant de photo qui n\'est pas un nombre', function () {
    $this->get('/admin/signalements/abc/photo')->assertNotFound();
});

// M9 : cartes de la maintenance sur la même population

it('M9 compte le coût et les interventions terminées sur la même population (début dans l\'année)', function () {
    $lastYear = today()->subYear();
    $job = revueJob($this->w->asset(), ['starts_on' => $lastYear->toDateString(), 'cost' => 500000]);
    app(CloseMaintenanceJob::class)($job, ['result' => MaintenanceResult::Repaired, 'condition_after_id' => $this->w->bon->id, 'status_after' => AssetStatus::InService, 'ends_on' => today()->toDateString()]);

    $year = today()->year;

    livewire(MaintenanceStatsWidget::class)
        ->assertSee('Terminées (début en '.$year.')')
        ->assertSee('Coût (début en '.$year.')')
        ->assertDontSee('500 000');
});

// R5, S1 : un bien sous intervention peut être prêté (QL8-10)

it('R5 un bien sous intervention en cours, non passé En maintenance, peut être prêté (QL8-10)', function () {
    $asset = $this->w->asset();
    revueJob($asset, ['put_in_maintenance' => false]);

    expect(revueLoan([$asset->id])->reference)->toStartWith('PRT-')
        ->and($asset->fresh()->status)->toBe(AssetStatus::OnLoan);
});

// S4 : le résultat est gardé pour la requête

it('S4 ne parcourt les routes qu\'une fois par requête', function () {
    DisposalUrl::forget();

    expect(app()->bound(DisposalUrl::CACHE_KEY))->toBeFalse();

    DisposalUrl::pageExists();

    expect(app()->bound(DisposalUrl::CACHE_KEY))->toBeTrue();
});
