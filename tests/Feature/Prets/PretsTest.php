<?php

use App\Actions\Assets\UpdateAsset;
use App\Actions\Loans\CreateLoan;
use App\Actions\Loans\RecordLoanReturn;
use App\Enums\AssetStatus;
use App\Enums\BorrowerType;
use App\Enums\LoanDirection;
use App\Enums\LoanStatus;
use App\Exceptions\ConcurrentModificationException;
use App\Models\Activity;
use App\Models\Asset;
use App\Models\Loan;
use App\Models\LoanItem;
use App\Models\User;
use App\Support\DefaultRoles;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\Support\OperationsWorld;

beforeEach(function () {
    $this->w = OperationsWorld::create();
    $this->actingAs($this->w->manager);
});

function loanData(array $overrides = []): array
{
    return [
        'direction' => LoanDirection::Out,
        'borrower_type' => BorrowerType::External,
        'borrower_name' => 'Chorale Béthel',
        'borrower_contact' => 'Mme Mabika',
        'out_on' => today()->subDays(2)->toDateString(),
        'due_on' => today()->addDays(5)->toDateString(),
        'responsible_id' => test()->w->manager->id,
        ...$overrides,
    ];
}

function returnData(Loan $loan, array $states, ?string $on = null): array
{
    $items = [];

    foreach ($loan->items as $index => $item) {
        $items[$item->id] = ['condition_back_id' => $states[$index] ?? $item->condition_out_id, 'return_notes' => null];
    }

    return ['returned_on' => $on ?? today()->toDateString(), 'items' => $items];
}

it('prête un bien disponible : statut Emprunté, statut précédent et état de départ conservés (RG-55, RG-56)', function () {
    $asset = $this->w->asset(['status' => AssetStatus::InStock]);

    $loan = app(CreateLoan::class)(loanData(), [$asset->id]);

    $item = $loan->items->sole();

    expect($loan->reference)->toMatch('/^PRT-\d{4}-001$/')
        ->and($loan->status)->toBe(LoanStatus::Ongoing)
        ->and($loan->direction)->toBe(LoanDirection::Out)
        ->and($item->asset_id)->toBe($asset->id)
        ->and($item->previous_status)->toBe(AssetStatus::InStock)
        ->and($item->condition_out_id)->toBe($this->w->bon->id)
        ->and($asset->fresh()->status)->toBe(AssetStatus::OnLoan);
});

it('prête plusieurs biens en un seul prêt, tout ou rien (RG-55)', function () {
    $a = $this->w->asset();
    $b = $this->w->asset(['status' => AssetStatus::InStock]);

    $loan = app(CreateLoan::class)(loanData(), [$a->id, $b->id]);

    expect($loan->items)->toHaveCount(2)
        ->and($a->fresh()->status)->toBe(AssetStatus::OnLoan)
        ->and($b->fresh()->status)->toBe(AssetStatus::OnLoan);

    $c = $this->w->asset();
    $maintenance = $this->w->asset(['status' => AssetStatus::InMaintenance]);

    expect(fn () => app(CreateLoan::class)(loanData(), [$c->id, $maintenance->id]))->toThrow(ValidationException::class)
        ->and($c->fresh()->status)->toBe(AssetStatus::InService)
        ->and(Loan::query()->count())->toBe(1);
});

it('refuse les biens qui ne sont pas disponibles : empruntés, en maintenance, hors service, sortis (RG-55)', function (AssetStatus $status) {
    $asset = $this->w->asset(['status' => $status]);

    expect(fn () => app(CreateLoan::class)(loanData(), [$asset->id]))->toThrow(ValidationException::class)
        ->and(Loan::query()->count())->toBe(0);
})->with([AssetStatus::OnLoan, AssetStatus::InMaintenance, AssetStatus::OutOfService, AssetStatus::Retired]);

it('refuse un bien sorti du patrimoine', function () {
    $asset = Asset::factory()->disposed()->create(['site_id' => $this->w->site->id, 'department_id' => $this->w->louange->id, 'asset_condition_id' => $this->w->bon->id]);

    expect(fn () => app(CreateLoan::class)(loanData(), [$asset->id]))->toThrow(ValidationException::class);
});

it('exige un bénéficiaire, des dates cohérentes et au moins un bien', function () {
    $asset = $this->w->asset();

    expect(fn () => app(CreateLoan::class)(loanData(['borrower_name' => ' ']), [$asset->id]))->toThrow(ValidationException::class)
        ->and(fn () => app(CreateLoan::class)(loanData(['due_on' => today()->subDays(9)->toDateString()]), [$asset->id]))->toThrow(ValidationException::class)
        ->and(fn () => app(CreateLoan::class)(loanData(['out_on' => today()->addDay()->toDateString()]), [$asset->id]))->toThrow(ValidationException::class)
        ->and(fn () => app(CreateLoan::class)(loanData(), []))->toThrow(ValidationException::class)
        ->and(fn () => app(CreateLoan::class)(loanData(['responsible_id' => 99999]), [$asset->id]))->toThrow(ValidationException::class);
});

it('prête à un département interne (RG-55)', function () {
    $asset = $this->w->asset();

    $loan = app(CreateLoan::class)(loanData(['borrower_type' => BorrowerType::Internal, 'borrower_name' => null, 'department_id' => $this->w->jeunesse->id]), [$asset->id]);

    expect($loan->borrower_type)->toBe(BorrowerType::Internal)
        ->and($loan->borrower_name)->toBe('Jeunesse')
        ->and($loan->department_id)->toBe($this->w->jeunesse->id);

    $this->w->jeunesse->update(['is_active' => false]);

    expect(fn () => app(CreateLoan::class)(loanData(['borrower_type' => BorrowerType::Internal, 'department_id' => $this->w->jeunesse->id]), [$this->w->asset()->id]))
        ->toThrow(ValidationException::class);
});

it('refuse d\'enregistrer un prêt sur un bien hors du périmètre de l\'utilisateur (RG-81)', function () {
    $mine = $this->w->asset();
    $other = $this->w->asset(['department_id' => $this->w->jeunesse->id]);

    $this->actingAs($this->w->restrictedManager($this->w->louange));

    expect(fn () => app(CreateLoan::class)(loanData(), [$other->id]))->toThrow(ValidationException::class)
        ->and($other->fresh()->status)->toBe(AssetStatus::InService)
        ->and(app(CreateLoan::class)(loanData(), [$mine->id])->items)->toHaveCount(1);
});

it('contrôle la version de la fiche sous verrou (RG-86)', function () {
    $asset = $this->w->asset();
    $opened = $asset->lock_version;

    app(UpdateAsset::class)($asset, [...$asset->only((new Asset)->getFillable()), 'notes' => 'Modifié entre-temps']);

    expect(fn () => app(CreateLoan::class)(loanData(), [$asset->id], [$asset->id => $opened]))->toThrow(ConcurrentModificationException::class)
        ->and(Loan::query()->count())->toBe(0)
        ->and($asset->fresh()->status)->toBe(AssetStatus::InService);

    $loan = app(CreateLoan::class)(loanData(), [$asset->id], [$asset->id => $asset->fresh()->lock_version]);

    expect($loan->exists)->toBeTrue()
        ->and($asset->fresh()->lock_version)->toBe($asset->fresh()->lock_version);
});

it('incrémente la version de la fiche à chaque changement de statut (RG-86)', function () {
    $asset = $this->w->asset();
    $before = $asset->lock_version;

    $loan = app(CreateLoan::class)(loanData(), [$asset->id]);
    $afterLoan = $asset->fresh()->lock_version;
    app(RecordLoanReturn::class)($loan, returnData($loan->load('items'), []));

    expect($afterLoan)->toBeGreaterThan($before)
        ->and($asset->fresh()->lock_version)->toBeGreaterThan($afterLoan);
});

it('trace le prêt au journal, avec le statut avant et après (RG-70)', function () {
    $asset = $this->w->asset();

    $loan = app(CreateLoan::class)(loanData(), [$asset->id]);

    $log = Activity::query()->where('log_name', 'prets')->where('event', 'loaned')->sole();

    expect($log->subject_id)->toBe($asset->id)
        ->and($log->causer_id)->toBe($this->w->manager->id)
        ->and($log->properties['old']['status'])->toBe('En service')
        ->and($log->properties['attributes']['status'])->toBe('Emprunté')
        ->and($log->properties['attributes']['reference'])->toBe($loan->reference);
});

it('numérote les prêts et les emprunts de façon unique et sans réutilisation (PRT, EMP, EXT)', function () {
    $first = app(CreateLoan::class)(loanData(), [$this->w->asset()->id]);
    $second = app(CreateLoan::class)(loanData(), [$this->w->asset()->id]);
    $year = today()->subDays(2)->year;

    $borrowing = fn () => app(CreateLoan::class)([
        'direction' => LoanDirection::In, 'borrower_name' => 'Mairie de Moungali', 'external_designation' => 'Podium mobile',
        'external_value' => 3500000, 'condition_out_id' => $this->w->bon->id,
        'out_on' => today()->toDateString(), 'due_on' => today()->addDays(10)->toDateString(), 'responsible_id' => $this->w->manager->id,
    ]);
    $firstBorrowing = $borrowing();
    $secondBorrowing = $borrowing();

    expect($first->reference)->toBe("PRT-{$year}-001")
        ->and($second->reference)->toBe("PRT-{$year}-002")
        ->and($firstBorrowing->reference)->toBe('EMP-'.today()->year.'-001')
        ->and($secondBorrowing->reference)->toBe('EMP-'.today()->year.'-002')
        ->and($firstBorrowing->items->sole()->external_reference)->toBe('EXT-0001')
        ->and($secondBorrowing->items->sole()->external_reference)->toBe('EXT-0002');

    // Une opération annulée ne laisse pas de trou : le compteur revient en arrière avec la transaction.
    try {
        app(CreateLoan::class)(loanData(), [$this->w->asset(['status' => AssetStatus::OnLoan])->id]);
    } catch (ValidationException) {
    }

    expect(app(CreateLoan::class)(loanData(), [$this->w->asset()->id])->reference)->toBe("PRT-{$year}-003");
});

it('les compteurs des prêts, des emprunts et du numéro patrimonial ne se mélangent pas', function () {
    app(CreateLoan::class)(loanData(), [$this->w->asset()->id]);

    $scopes = DB::table('asset_number_sequences')->pluck('last_value', 'scope')->all();

    expect($scopes)->toHaveKey('PRT-'.today()->subDays(2)->year)
        ->and(collect($scopes)->keys()->filter(fn (string $scope): bool => str_starts_with($scope, 'PAT') || $scope === 'global'))->toBeEmpty();
});

it('enregistre un emprunt sans fiche, hors patrimoine (RG-58)', function () {
    $assets = Asset::query()->count();

    $loan = app(CreateLoan::class)([
        'direction' => LoanDirection::In, 'borrower_name' => 'Société Event Pro', 'external_designation' => 'Chaises (lot de 200)',
        'external_value' => 1000000, 'condition_out_id' => $this->w->bon->id,
        'out_on' => today()->toDateString(), 'due_on' => today()->addDays(30)->toDateString(), 'responsible_id' => $this->w->manager->id,
    ]);

    $item = $loan->items->sole();

    expect($loan->direction)->toBe(LoanDirection::In)
        ->and($loan->borrower_type)->toBe(BorrowerType::External)
        ->and($item->asset_id)->toBeNull()
        ->and($item->external_value)->toBe(1000000)
        ->and($item->external_reference)->toBe('EXT-0001')
        ->and(Asset::query()->count())->toBe($assets)
        ->and(Activity::query()->where('log_name', 'prets')->where('event', 'borrowed')->count())->toBe(1);

    expect(fn () => app(CreateLoan::class)([...loanData(), 'direction' => LoanDirection::In, 'external_designation' => '', 'condition_out_id' => $this->w->bon->id], []))->toThrow(ValidationException::class)
        ->and(fn () => app(CreateLoan::class)([...loanData(), 'direction' => LoanDirection::In, 'external_designation' => 'Écran', 'condition_out_id' => $this->w->bon->id], [$this->w->asset()->id]))->toThrow(ValidationException::class);
});

it('un emprunt ne fait partie ni des biens ni des amortissements (RG-58)', function () {
    app(CreateLoan::class)([
        'direction' => LoanDirection::In, 'borrower_name' => 'Église sœur', 'external_designation' => 'Écran LED',
        'external_value' => 2200000, 'condition_out_id' => $this->w->bon->id,
        'out_on' => today()->toDateString(), 'due_on' => today()->addDays(10)->toDateString(), 'responsible_id' => $this->w->manager->id,
    ]);

    expect(Asset::query()->count())->toBe(0)
        ->and(DB::table('depreciation_schedules')->count())->toBe(0)
        ->and(LoanItem::query()->whereNull('asset_id')->count())->toBe(1);
});

it('enregistre le retour : date effective, état au retour, statut précédent restauré (RG-56)', function () {
    $stock = $this->w->asset(['status' => AssetStatus::InStock]);
    $service = $this->w->asset();
    $loan = app(CreateLoan::class)(loanData(), [$stock->id, $service->id])->load('items');

    $returned = app(RecordLoanReturn::class)($loan, returnData($loan, []));

    expect($returned->status)->toBe(LoanStatus::Returned)
        ->and($returned->returned_on->toDateString())->toBe(today()->toDateString())
        ->and($stock->fresh()->status)->toBe(AssetStatus::InStock)
        ->and($service->fresh()->status)->toBe(AssetStatus::InService)
        ->and($returned->items->every(fn (LoanItem $item): bool => $item->condition_back_id === $item->condition_out_id))->toBeTrue();
});

it('met à jour l\'état du bien quand il revient en moins bon état, et le signale (RG-57)', function () {
    $asset = $this->w->asset();
    $loan = app(CreateLoan::class)(loanData(), [$asset->id])->load('items');

    $returned = app(RecordLoanReturn::class)($loan, returnData($loan, [$this->w->moyen->id]));

    expect($asset->fresh()->asset_condition_id)->toBe($this->w->moyen->id)
        ->and($asset->fresh()->status)->toBe(AssetStatus::InService)
        ->and($returned->items->sole()->isDegraded())->toBeTrue()
        ->and($returned->items->sole()->conditionOut->label)->toBe('Bon état');
});

it('ne touche pas l\'état d\'un bien rendu dans le même état ou en meilleur état (RG-57)', function () {
    $same = $this->w->asset();
    $better = $this->w->asset();
    $loan = app(CreateLoan::class)(loanData(), [$same->id, $better->id])->load('items');

    $returned = app(RecordLoanReturn::class)($loan, returnData($loan, [$this->w->bon->id, $this->w->neuf->id]));

    expect($same->fresh()->asset_condition_id)->toBe($this->w->bon->id)
        ->and($better->fresh()->asset_condition_id)->toBe($this->w->bon->id)
        ->and($returned->items->contains(fn (LoanItem $item): bool => $item->isDegraded()))->toBeFalse();
});

it('compare les états avec le champ « sort », pas avec l\'ordre des identifiants (RG-57)', function () {
    $asset = $this->w->asset(['asset_condition_id' => $this->w->aReparer->id]);
    $loan = app(CreateLoan::class)(loanData(), [$asset->id])->load('items');

    app(RecordLoanReturn::class)($loan, returnData($loan, [$this->w->neuf->id]));

    expect($asset->fresh()->asset_condition_id)->toBe($this->w->aReparer->id);
});

it('trace le retour au journal avec le statut et l\'état avant et après (RG-70)', function () {
    $asset = $this->w->asset();
    $loan = app(CreateLoan::class)(loanData(), [$asset->id])->load('items');

    app(RecordLoanReturn::class)($loan, returnData($loan, [$this->w->aReparer->id]));

    $log = Activity::query()->where('log_name', 'prets')->where('event', 'returned')->sole();

    expect($log->properties['old']['status'])->toBe('Emprunté')
        ->and($log->properties['attributes']['status'])->toBe('En service')
        ->and($log->properties['old']['condition'])->toBe('Bon état')
        ->and($log->properties['attributes']['condition'])->toBe('À réparer');
});

it('restitue un emprunt sans toucher aucune fiche', function () {
    $loan = app(CreateLoan::class)([
        'direction' => LoanDirection::In, 'borrower_name' => 'Mairie', 'external_designation' => 'Podium',
        'condition_out_id' => $this->w->bon->id, 'out_on' => today()->toDateString(), 'due_on' => today()->addDays(3)->toDateString(),
        'responsible_id' => $this->w->manager->id,
    ])->load('items');

    $returned = app(RecordLoanReturn::class)($loan, returnData($loan, [$this->w->moyen->id]));

    expect($returned->status)->toBe(LoanStatus::Returned)
        ->and($returned->items->sole()->isDegraded())->toBeTrue()
        ->and(Asset::query()->count())->toBe(0);
});

it('refuse un retour déjà enregistré, antérieur à la sortie, incomplet ou portant sur une autre ligne', function () {
    $asset = $this->w->asset();
    $other = $this->w->asset();
    $loan = app(CreateLoan::class)(loanData(), [$asset->id])->load('items');
    $otherLoan = app(CreateLoan::class)(loanData(), [$other->id])->load('items');

    expect(fn () => app(RecordLoanReturn::class)($loan, returnData($loan, [], today()->subDays(10)->toDateString())))->toThrow(ValidationException::class)
        ->and(fn () => app(RecordLoanReturn::class)($loan, returnData($loan, [], today()->addDay()->toDateString())))->toThrow(ValidationException::class)
        ->and(fn () => app(RecordLoanReturn::class)($loan, ['returned_on' => today()->toDateString(), 'items' => []]))->toThrow(ValidationException::class)
        ->and(fn () => app(RecordLoanReturn::class)($loan, ['returned_on' => today()->toDateString(), 'items' => [$otherLoan->items->first()->id => ['condition_back_id' => $this->w->bon->id]]]))->toThrow(ValidationException::class)
        ->and(fn () => app(RecordLoanReturn::class)($loan, ['returned_on' => today()->toDateString(), 'items' => [$loan->items->first()->id => ['condition_back_id' => 99999]]]))->toThrow(ValidationException::class)
        ->and($asset->fresh()->status)->toBe(AssetStatus::OnLoan);

    app(RecordLoanReturn::class)($loan, returnData($loan, []));

    expect(fn () => app(RecordLoanReturn::class)($loan, returnData($loan, [])))->toThrow(ValidationException::class);
});

it('un prêt rendu ne se modifie plus et rien ne se supprime', function () {
    $loan = app(CreateLoan::class)(loanData(), [$this->w->asset()->id])->load('items');
    app(RecordLoanReturn::class)($loan, returnData($loan, []));
    $loan = $loan->fresh();

    expect(fn () => $loan->update(['borrower_name' => 'Autre']))->toThrow(LogicException::class)
        ->and(fn () => $loan->delete())->toThrow(LogicException::class)
        ->and(fn () => $loan->items()->first()->update(['return_notes' => 'x']))->toThrow(LogicException::class)
        ->and(fn () => $loan->items()->first()->delete())->toThrow(LogicException::class);
});

it('contrôle la version de la fiche au retour (RG-86)', function () {
    $asset = $this->w->asset();
    $loan = app(CreateLoan::class)(loanData(), [$asset->id])->load('items');
    $opened = $asset->fresh()->lock_version;

    app(UpdateAsset::class)($asset->fresh(), [...$asset->fresh()->only((new Asset)->getFillable()), 'notes' => 'Modifié pendant le prêt']);

    expect(fn () => app(RecordLoanReturn::class)($loan, returnData($loan, []), [$asset->id => $opened]))->toThrow(ConcurrentModificationException::class)
        ->and($loan->fresh()->status)->toBe(LoanStatus::Ongoing)
        ->and($asset->fresh()->status)->toBe(AssetStatus::OnLoan);
});

it('un statut Emprunté ne se change pas à la main pendant le prêt (RG-12)', function () {
    $asset = $this->w->asset();
    app(CreateLoan::class)(loanData(), [$asset->id]);

    $asset = $asset->fresh();
    app(UpdateAsset::class)($asset, [...$asset->only((new Asset)->getFillable()), 'status' => AssetStatus::InService]);

    expect($asset->fresh()->status)->toBe(AssetStatus::OnLoan);
});

it('refuse un retour qui laisserait de côté un bien hors du périmètre (RG-81)', function () {
    $mine = $this->w->asset();
    $other = $this->w->asset(['department_id' => $this->w->jeunesse->id]);
    $loan = app(CreateLoan::class)(loanData(), [$mine->id, $other->id]);

    $this->actingAs($this->w->restrictedManager($this->w->louange));
    $visible = Loan::query()->findOrFail($loan->id)->load('items');

    expect($visible->items)->toHaveCount(1)
        ->and(fn () => app(RecordLoanReturn::class)($visible, returnData($visible, [])))->toThrow(ValidationException::class)
        ->and($mine->fresh()->status)->toBe(AssetStatus::OnLoan);
});

it('applique les droits : le responsable de département consulte mais ne prête ni ne reçoit de retour (matrice)', function () {
    $head = $this->w->head($this->w->louange);
    $asset = $this->w->asset();
    $loan = app(CreateLoan::class)(loanData(), [$asset->id])->load('items');

    $this->actingAs($head);

    expect($head->can('viewAny', Loan::class))->toBeTrue()
        ->and($head->can('create', Loan::class))->toBeFalse()
        ->and($head->can('update', $loan))->toBeFalse()
        ->and(fn () => app(CreateLoan::class)(loanData(), [$this->w->asset()->id]))->toThrow(AuthorizationException::class)
        ->and(fn () => app(RecordLoanReturn::class)($loan, returnData($loan, [])))->toThrow(AuthorizationException::class);

    $accountant = User::factory()->role(DefaultRoles::ACCOUNTANT)->create();

    expect($accountant->can('viewAny', Loan::class))->toBeTrue()
        ->and($accountant->can('create', Loan::class))->toBeFalse();
});

it('un prêt ne se supprime pas (policy)', function () {
    $loan = app(CreateLoan::class)(loanData(), [$this->w->asset()->id]);
    $admin = User::factory()->admin()->create();

    expect($admin->can('delete', $loan))->toBeFalse()
        ->and($admin->can('deleteAny', Loan::class))->toBeFalse();
});
