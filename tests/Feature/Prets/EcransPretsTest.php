<?php

use App\Actions\Loans\CreateLoan as CreateLoanAction;
use App\Actions\Loans\RecordLoanReturn;
use App\Enums\AssetStatus;
use App\Enums\BorrowerType;
use App\Enums\LoanDirection;
use App\Enums\LoanStatus;
use App\Filament\Resources\Assets\Pages\ViewAsset;
use App\Filament\Resources\Assets\RelationManagers\LoansRelationManager;
use App\Filament\Resources\Loans\LoanResource;
use App\Filament\Resources\Loans\Pages\CreateLoan;
use App\Filament\Resources\Loans\Pages\ListLoans;
use App\Filament\Resources\Loans\Pages\ViewLoan;
use App\Filament\Resources\Maintenance\MaintenanceResource;
use App\Models\Asset;
use App\Models\Loan;
use App\Models\LoanItem;
use App\Models\User;
use App\Support\DefaultRoles;
use Filament\Facades\Filament;
use Livewire\Livewire;
use Tests\Support\OperationsWorld;

use function Pest\Livewire\livewire;

beforeEach(function () {
    Filament::setCurrentPanel(Filament::getPanel('admin'));

    $this->w = OperationsWorld::create();
    $this->actingAs($this->w->manager);
    $this->asset = $this->w->asset();
});

function screenLoan(array $overrides = [], ?array $assets = null): Loan
{
    return app(CreateLoanAction::class)([
        'direction' => LoanDirection::Out,
        'borrower_type' => BorrowerType::External,
        'borrower_name' => 'Chorale Béthel',
        'out_on' => today()->subDays(10)->toDateString(),
        'due_on' => today()->addDays(5)->toDateString(),
        'responsible_id' => test()->w->manager->id,
        ...$overrides,
    ], $assets ?? [test()->w->asset()->id]);
}

it('affiche les prêts dans les onglets en cours, en retard, emprunts et historique (E15)', function () {
    $ongoing = screenLoan();
    $late = screenLoan(['due_on' => today()->subDays(3)->toDateString()]);
    $borrowing = app(CreateLoanAction::class)([
        'direction' => LoanDirection::In, 'borrower_name' => 'Mairie', 'external_designation' => 'Podium', 'condition_out_id' => $this->w->bon->id,
        'out_on' => today()->toDateString(), 'due_on' => today()->addDays(9)->toDateString(), 'responsible_id' => $this->w->manager->id,
    ]);
    $returned = screenLoan();
    app(RecordLoanReturn::class)($returned->load('items'), ['returned_on' => today()->toDateString(), 'items' => [$returned->items->first()->id => ['condition_back_id' => $this->w->bon->id]]]);

    livewire(ListLoans::class)
        ->assertCanSeeTableRecords([$ongoing, $late])
        ->assertCanNotSeeTableRecords([$borrowing, $returned])
        ->set('activeTab', 'late')
        ->assertCanSeeTableRecords([$late])
        ->assertCanNotSeeTableRecords([$ongoing])
        ->set('activeTab', 'borrowed')
        ->assertCanSeeTableRecords([$borrowing])
        ->assertCanNotSeeTableRecords([$ongoing, $late])
        ->set('activeTab', 'history')
        ->assertCanSeeTableRecords([$returned])
        ->assertCanNotSeeTableRecords([$ongoing, $borrowing]);
});

it('enregistre un prêt depuis le formulaire (E16, RG-55, RG-56)', function () {
    $stock = $this->w->asset(['status' => AssetStatus::InStock]);

    livewire(CreateLoan::class)
        ->set('data.asset_ids', [$this->asset->id, $stock->id])
        ->fillForm([
            'direction' => 'out',

            'borrower_type' => 'external',
            'borrower_name' => 'Chorale Béthel',
            'borrower_contact' => 'Mme Mabika · +242 05 000 00 00',
            'out_on' => today()->toDateString(),
            'due_on' => today()->addDays(7)->toDateString(),
            'responsible_id' => $this->w->manager->id,
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $loan = Loan::query()->sole();

    expect($loan->items)->toHaveCount(2)
        ->and($loan->borrower_name)->toBe('Chorale Béthel')
        ->and($this->asset->fresh()->status)->toBe(AssetStatus::OnLoan)
        ->and($stock->fresh()->status)->toBe(AssetStatus::OnLoan);
});

it('enregistre un prêt interne à un département', function () {
    livewire(CreateLoan::class)
        ->set('data.asset_ids', [$this->asset->id])
        ->fillForm([
            'direction' => 'out',  'borrower_type' => 'internal', 'department_id' => $this->w->jeunesse->id,
            'out_on' => today()->toDateString(), 'due_on' => today()->addDays(7)->toDateString(), 'responsible_id' => $this->w->manager->id,
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    expect(Loan::query()->sole()->borrower_name)->toBe('Jeunesse');
});

it('enregistre un emprunt depuis le formulaire (E16, RG-58)', function () {
    livewire(CreateLoan::class)
        ->fillForm([
            'direction' => 'in',
            'external_designation' => 'Podium mobile 8 x 6 m',
            'external_value' => 3500000,
            'condition_out_id' => $this->w->bon->id,
            'borrower_name' => 'Mairie de Moungali',
            'out_on' => today()->toDateString(),
            'due_on' => today()->addDays(14)->toDateString(),
            'responsible_id' => $this->w->manager->id,
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $loan = Loan::query()->sole();

    expect($loan->direction)->toBe(LoanDirection::In)
        ->and($loan->reference)->toStartWith('EMP-')
        ->and($loan->items->sole()->external_reference)->toBe('EXT-0001');
});

it('préselectionne le bien quand on arrive depuis sa fiche (action Prêter)', function () {
    Livewire::withQueryParams(['bien' => $this->asset->id])
        ->test(CreateLoan::class)
        ->assertFormSet(['asset_ids' => [$this->asset->id]]);
});

it('garde le formulaire ouvert et ne change rien quand le prêt est refusé', function () {
    $maintenance = $this->w->asset(['status' => AssetStatus::InMaintenance]);

    livewire(CreateLoan::class)
        ->set('data.asset_ids', [$maintenance->id])
        ->fillForm([
            'direction' => 'out',  'borrower_type' => 'external', 'borrower_name' => 'X',
            'out_on' => today()->toDateString(), 'due_on' => today()->addDays(7)->toDateString(), 'responsible_id' => $this->w->manager->id,
        ])
        ->call('create')
        ->assertNotified('Enregistrement impossible');

    expect(Loan::query()->count())->toBe(0)
        ->and($maintenance->fresh()->status)->toBe(AssetStatus::InMaintenance);
});

it('refuse de prêter, par manipulation du formulaire, un bien hors du périmètre (RG-81)', function () {
    $foreign = $this->w->asset(['department_id' => $this->w->jeunesse->id]);
    $this->actingAs($this->w->restrictedManager($this->w->louange));

    livewire(CreateLoan::class)
        ->set('data.asset_ids', [$foreign->id])
        ->fillForm([
            'direction' => 'out',  'borrower_type' => 'external', 'borrower_name' => 'X',
            'out_on' => today()->toDateString(), 'due_on' => today()->addDays(7)->toDateString(), 'responsible_id' => auth()->id(),
        ])
        ->call('create');

    expect(Loan::query()->withoutGlobalScopes()->count())->toBe(0)
        ->and($foreign->fresh()->status)->toBe(AssetStatus::InService);
});

it('enregistre le retour depuis la liste : états par bien, statut restauré (E17, RG-56)', function () {
    $loan = screenLoan();
    $item = $loan->items->sole();

    livewire(ListLoans::class)
        ->callTableAction('return', $loan, [
            'returned_on' => today()->toDateString(),
            'versions' => [],
            'items' => [['id' => $item->id, 'label' => 'x', 'condition_out_id' => $item->condition_out_id, 'condition_back_id' => $this->w->bon->id, 'return_notes' => null]],
        ])
        ->assertHasNoTableActionErrors()
        ->assertNotified('Retour enregistré');

    expect($loan->fresh()->status)->toBe(LoanStatus::Returned)
        ->and($item->asset->fresh()->status)->toBe(AssetStatus::InService);
});

it('un retour dégradé met à jour l\'état et propose l\'intervention (RG-57)', function () {
    $loan = screenLoan();
    $item = $loan->items->sole();

    livewire(ViewLoan::class, ['record' => $loan->getRouteKey()])
        ->callAction('return', [
            'returned_on' => today()->toDateString(),
            'open_maintenance' => true,
            'is_out' => true,
            'items' => [['id' => $item->id, 'label' => 'x', 'condition_out_id' => $item->condition_out_id, 'condition_back_id' => $this->w->aReparer->id, 'return_notes' => 'Grille enfoncée']],
        ])
        ->assertRedirect(MaintenanceResource::getUrl('create').'?'.http_build_query(['bien' => $item->asset_id]));

    expect($item->asset->fresh()->asset_condition_id)->toBe($this->w->aReparer->id)
        ->and($loan->fresh()->status)->toBe(LoanStatus::Returned);
});

it('un retour dégradé sans la case d\'intervention ne redirige pas', function () {
    $loan = screenLoan();
    $item = $loan->items->sole();

    livewire(ViewLoan::class, ['record' => $loan->getRouteKey()])
        ->callAction('return', [
            'returned_on' => today()->toDateString(),
            'open_maintenance' => false,
            'items' => [['id' => $item->id, 'label' => 'x', 'condition_out_id' => $item->condition_out_id, 'condition_back_id' => $this->w->moyen->id]],
        ])
        ->assertNoRedirect();

    expect($loan->fresh()->status)->toBe(LoanStatus::Returned);
});

it('refuse un retour manipulé : ligne d\'un autre prêt ou ligne manquante', function () {
    $loan = screenLoan();
    $other = screenLoan();

    livewire(ViewLoan::class, ['record' => $loan->getRouteKey()])
        ->callAction('return', [
            'returned_on' => today()->toDateString(),
            'items' => [['id' => $other->items->first()->id, 'label' => 'x', 'condition_back_id' => $this->w->bon->id]],
        ])
        ->assertNotified('Retour impossible');

    expect($loan->fresh()->status)->toBe(LoanStatus::Ongoing)
        ->and($other->fresh()->status)->toBe(LoanStatus::Ongoing)
        ->and($loan->items->first()->asset->fresh()->status)->toBe(AssetStatus::OnLoan);
});

it('restitue un emprunt depuis sa fiche', function () {
    $loan = app(CreateLoanAction::class)([
        'direction' => LoanDirection::In, 'borrower_name' => 'Mairie', 'external_designation' => 'Podium', 'condition_out_id' => $this->w->bon->id,
        'out_on' => today()->toDateString(), 'due_on' => today()->addDays(9)->toDateString(), 'responsible_id' => $this->w->manager->id,
    ]);
    $item = $loan->items->sole();

    livewire(ViewLoan::class, ['record' => $loan->getRouteKey()])
        ->assertActionExists('return')
        ->callAction('return', [
            'returned_on' => today()->toDateString(),
            'items' => [['id' => $item->id, 'label' => 'x', 'condition_back_id' => $this->w->bon->id]],
        ]);

    expect($loan->fresh()->status)->toBe(LoanStatus::Returned);
});

it('la fiche d\'un prêt montre les deux états et le retour', function () {
    $loan = screenLoan();
    $item = $loan->items->sole();
    app(RecordLoanReturn::class)($loan->load('items'), ['returned_on' => today()->toDateString(), 'items' => [$item->id => ['condition_back_id' => $this->w->moyen->id, 'return_notes' => 'Rayures']]]);

    livewire(ViewLoan::class, ['record' => $loan->getRouteKey()])
        ->assertSee($loan->reference)
        ->assertSee('Bon état')
        ->assertSee('État moyen')
        ->assertSee('Rayures')
        ->assertSee('Rendu, état dégradé')
        ->assertActionHidden('return');
});

it('le responsable de département ne voit que les prêts de son périmètre, sans pouvoir prêter ni enregistrer un retour (RG-81)', function () {
    $mine = screenLoan(assets: [$this->asset->id]);
    $foreign = screenLoan(assets: [$this->w->asset(['department_id' => $this->w->jeunesse->id])->id]);
    $borrowing = app(CreateLoanAction::class)([
        'direction' => LoanDirection::In, 'borrower_name' => 'Mairie', 'external_designation' => 'Podium', 'condition_out_id' => $this->w->bon->id,
        'out_on' => today()->toDateString(), 'due_on' => today()->addDays(9)->toDateString(), 'responsible_id' => $this->w->manager->id,
    ]);

    $head = $this->w->head($this->w->louange);
    $this->actingAs($head);

    livewire(ListLoans::class)
        ->assertCanSeeTableRecords([$mine])
        ->assertCanNotSeeTableRecords([$foreign])
        ->assertTableActionHidden('return', $mine)
        ->set('activeTab', 'borrowed')
        ->assertCanNotSeeTableRecords([$borrowing]);

    $this->get(LoanResource::getUrl('view', ['record' => $mine]))->assertOk();
    $this->get(LoanResource::getUrl('view', ['record' => $foreign]))->assertNotFound();
    $this->get(LoanResource::getUrl('view', ['record' => $borrowing]))->assertNotFound();
    $this->get(LoanResource::getUrl('create'))->assertForbidden();
    $this->get(LoanResource::getUrl('index'))->assertOk();
});

it('un emprunt est refusé à un utilisateur au périmètre restreint (QL8-1)', function () {
    $this->actingAs($this->w->restrictedManager($this->w->louange));

    livewire(CreateLoan::class)
        ->fillForm([
            'direction' => 'in', 'external_designation' => 'Podium', 'condition_out_id' => $this->w->bon->id, 'borrower_name' => 'Mairie',
            'out_on' => today()->toDateString(), 'due_on' => today()->addDays(9)->toDateString(), 'responsible_id' => auth()->id(),
        ])
        ->call('create')
        ->assertNotified('Enregistrement impossible');

    expect(Loan::query()->withoutGlobalScopes()->count())->toBe(0);
});

it('les lignes d\'un prêt mixte hors périmètre restent invisibles (RG-81)', function () {
    $loan = screenLoan(assets: [$this->asset->id, $this->w->asset(['department_id' => $this->w->jeunesse->id])->id]);

    $this->actingAs($this->w->head($this->w->louange));

    expect(LoanItem::query()->where('loan_id', $loan->id)->count())->toBe(1)
        ->and(Loan::query()->findOrFail($loan->id)->items)->toHaveCount(1);
});

it('affiche les actions Prêter, Maintenance et Signaler sur la fiche du bien, selon les droits (E06)', function () {
    livewire(ViewAsset::class, ['record' => $this->asset->getRouteKey()])
        ->assertActionVisible('loan')
        ->assertActionVisible('maintenance')
        ->assertActionVisible('report');

    $onLoan = $this->w->asset(['status' => AssetStatus::OnLoan]);

    livewire(ViewAsset::class, ['record' => $onLoan->getRouteKey()])->assertActionHidden('loan');

    $this->actingAs($this->w->head($this->w->louange));

    livewire(ViewAsset::class, ['record' => $this->asset->getRouteKey()])
        ->assertActionHidden('loan')
        ->assertActionHidden('maintenance')
        ->assertActionVisible('report');

    $disposed = Asset::factory()->disposed()->create(['site_id' => $this->w->site->id, 'department_id' => $this->w->louange->id, 'asset_condition_id' => $this->w->bon->id]);
    $this->actingAs($this->w->manager);

    livewire(ViewAsset::class, ['record' => $disposed->getRouteKey()])
        ->assertActionHidden('loan')
        ->assertActionHidden('maintenance')
        ->assertActionHidden('report');
});

it('l\'onglet Prêts de la fiche du bien liste ses prêts (E06)', function () {
    $loan = screenLoan(assets: [$this->asset->id]);
    app(RecordLoanReturn::class)($loan->load('items'), ['returned_on' => today()->toDateString(), 'items' => [$loan->items->first()->id => ['condition_back_id' => $this->w->bon->id]]]);
    $item = LoanItem::query()->where('asset_id', $this->asset->id)->sole();

    livewire(LoansRelationManager::class, ['ownerRecord' => $this->asset, 'pageClass' => ViewAsset::class])
        ->assertCanSeeTableRecords([$item])
        ->assertSee($loan->reference);
});

it('l\'accès aux prêts est refusé sans le droit de consultation', function () {
    $this->actingAs(User::factory()->role(DefaultRoles::ACCOUNTANT)->create());
    $this->get(LoanResource::getUrl('index'))->assertOk();

    $nobody = User::factory()->create();
    $this->actingAs($nobody);
    $this->get(LoanResource::getUrl('index'))->assertForbidden();
});
