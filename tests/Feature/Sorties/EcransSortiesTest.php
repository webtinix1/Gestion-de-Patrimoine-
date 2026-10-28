<?php

use App\Actions\Assets\UpdateAsset;
use App\Enums\AssetStatus;
use App\Enums\DisposalReason;
use App\Filament\Resources\Activities\ActivityResource;
use App\Filament\Resources\Assets\Pages\AssetDepreciationSchedule;
use App\Filament\Resources\Assets\Pages\ViewAsset;
use App\Filament\Resources\Assets\RelationManagers\DocumentsRelationManager;
use App\Filament\Resources\Disposals\DisposalResource;
use App\Filament\Resources\Disposals\Pages\CreateDisposal;
use App\Filament\Resources\Disposals\Pages\ListDisposals;
use App\Filament\Resources\Disposals\Pages\ViewDisposal;
use App\Models\Asset;
use App\Models\Department;
use App\Models\Disposal;
use App\Models\User;
use App\Support\DefaultRoles;
use Filament\Actions\EditAction;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;

use function Pest\Livewire\livewire;

require_once __DIR__.'/helpers.php';

function nbsp(string $amount): string
{
    return str_replace(' ', "\u{00A0}", $amount);
}

/**
 * @return array<string, mixed>
 */
function disposalForm(Asset $asset, array $overrides = []): array
{
    return [
        'asset_id' => $asset->id,
        'reason' => DisposalReason::Retirement->value,
        'disposed_on' => '2026-10-26',
        'decided_by' => 'Conseil d\'administration',
        'decision_reference' => 'PV CA du 24/10/2026',
        ...$overrides,
    ];
}

beforeEach(function () {
    Carbon::setTestNow('2026-10-28 10:00:00');
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    disposalFiscalYears();

    $this->manager = User::factory()->role(DefaultRoles::MANAGER)->create();
    $this->accountant = User::factory()->role(DefaultRoles::ACCOUNTANT)->create();
    $this->asset = a5Asset();
});

afterEach(fn () => Carbon::setTestNow());

it('range « Sorties et réformes » en dernière entrée du groupe Opérations', function () {
    expect(DisposalResource::getNavigationGroup())->toBe('Opérations')
        ->and(DisposalResource::getNavigationLabel())->toBe('Sorties et réformes')
        ->and(DisposalResource::getNavigationSort())->toBeGreaterThan(10)
        ->and(DisposalResource::getUrl('create'))->toEndWith('/admin/sorties/create')
        ->and(ActivityResource::LOG_NAMES)->toHaveKey('sorties');
});

it('ouvre les écrans selon la matrice (consulter, créer)', function () {
    $this->actingAs($this->manager);
    $this->get('/admin/sorties')->assertOk();
    $this->get('/admin/sorties/create')->assertOk();

    $this->actingAs($this->accountant);
    $this->get('/admin/sorties')->assertOk();
    $this->get('/admin/sorties/create')->assertForbidden();

    $this->actingAs(User::factory()->role(DefaultRoles::DEPARTMENT_HEAD)->create());
    $this->get('/admin/sorties')->assertForbidden();
    $this->get('/admin/sorties/create')->assertForbidden();
});

it('enregistre une sortie après la saisie du numéro du bien, puis ouvre sa fiche (E26, RG-53)', function () {
    $this->actingAs($this->manager);

    livewire(CreateDisposal::class)
        ->fillForm(disposalForm($this->asset))
        ->assertSee(nbsp('2 620 000'))
        ->assertSee(nbsp('2 180 000'))
        ->assertSee(nbsp('493 333'))
        ->callAction(confirmDisposal(), data: ['confirmation' => $this->asset->number])
        ->assertHasNoFormErrors()
        ->assertRedirect(DisposalResource::getUrl('view', ['record' => Disposal::query()->sole()]));

    expect($this->asset->fresh()->status)->toBe(AssetStatus::Retired)
        ->and(Disposal::query()->sole()->net_book_value)->toBe(2180000);
});

it('n\'enregistre rien avec un numéro erroné ou sans confirmation', function () {
    $this->actingAs($this->manager);

    livewire(CreateDisposal::class)
        ->fillForm(disposalForm($this->asset))
        ->callAction(confirmDisposal(), data: ['confirmation' => 'PAT-999999'])
        ->assertNotified('Sortie refusée');

    // Manipulation Livewire : appel direct de la méthode d'enregistrement, sans la fenêtre de confirmation.
    livewire(CreateDisposal::class)
        ->fillForm(disposalForm($this->asset))
        ->call('create')
        ->assertNotified('Sortie refusée');

    expect(Disposal::query()->count())->toBe(0)
        ->and($this->asset->fresh()->isDisposed())->toBeFalse();
});

it('montre les champs de cession et la moins-value indicative (RG-51, Q8)', function () {
    $this->actingAs($this->manager);

    livewire(CreateDisposal::class)
        ->fillForm(disposalForm($this->asset, ['reason' => 'sale', 'buyer_or_beneficiary' => 'Énergie Solaire Plus', 'sale_price' => '500000']))
        ->assertSee('Moins-value indicative')
        ->assertSee(nbsp('1 680 000'))
        ->callAction(confirmDisposal(), data: ['confirmation' => $this->asset->number])
        ->assertHasNoFormErrors();

    expect(Disposal::query()->sole()->indicativeGain())->toBe(-1680000);

    livewire(ViewDisposal::class, ['record' => Disposal::query()->sole()->getRouteKey()])
        ->assertSee('Moins-value indicative')
        ->assertSee('Énergie Solaire Plus');
});

it('affiche les erreurs du formulaire quand la sortie est refusée', function () {
    $this->actingAs($this->manager);

    livewire(CreateDisposal::class)
        ->fillForm(disposalForm($this->asset, ['disposed_on' => '2025-06-30']))
        ->callAction(confirmDisposal(), data: ['confirmation' => $this->asset->number])
        ->assertHasErrors(['data.disposed_on']);

    expect(Disposal::query()->count())->toBe(0);
});

it('préremplit le bien et le motif depuis l\'adresse (contrat des lots 8 et 9)', function () {
    $this->actingAs($this->manager);

    Livewire::withQueryParams(['bien' => (string) $this->asset->id, 'motif' => 'destruction', 'inventaire_ligne' => '42'])
        ->test(CreateDisposal::class)
        ->assertSet('data.asset_id', $this->asset->id)
        ->assertSet('data.reason', DisposalReason::Destruction->value)
        ->assertSet('inventoryLineId', 42)
        ->fillForm(['decided_by' => 'Administrateur'])
        // Sans la classe ResolveInventoryLine (lot 9), le paramètre est ignoré.
        ->callAction(confirmDisposal(), data: ['confirmation' => $this->asset->number])
        ->assertHasNoFormErrors();

    expect(Disposal::query()->sole()->reason)->toBe(DisposalReason::Destruction);
});

it('ignore un préremplissage invalide, hors périmètre ou déjà sorti', function () {
    $own = Department::factory()->create();
    $this->manager->departments()->attach($own);
    $this->actingAs($this->manager->fresh());
    $disposed = createDepreciableAsset(['department_id' => $own->id]);
    recordDisposal($disposed);

    foreach ([['bien' => (string) $this->asset->id], ['bien' => (string) $disposed->id], ['bien' => 'abc', 'motif' => 'vente']] as $query) {
        Livewire::withQueryParams($query)
            ->test(CreateDisposal::class)
            ->assertSet('data.asset_id', null)
            ->assertSet('data.reason', null);
    }
});

it('refuse un bien hors périmètre glissé dans le formulaire (manipulation Livewire)', function () {
    $own = Department::factory()->create();
    $this->manager->departments()->attach($own);
    $this->actingAs($this->manager->fresh());

    $page = livewire(CreateDisposal::class)
        ->set('data.asset_id', $this->asset->id)
        ->fillForm(disposalForm($this->asset))
        ->assertSet('assetVersions', [])
        ->callAction(confirmDisposal(), data: ['confirmation' => $this->asset->number]);

    expect(Disposal::query()->withoutGlobalScopes()->count())->toBe(0)
        ->and(Asset::query()->withoutGlobalScopes()->find($this->asset->id)->isDisposed())->toBeFalse();

    // Même en contournant la vérification du formulaire, l'action métier refuse le bien (périmètre).
    expect(fn () => recordDisposal($this->asset))->toThrow(ValidationException::class, 'hors de votre périmètre');
});

it('verrouille la version mémorisée et la ligne d\'inventaire côté serveur', function (string $property, mixed $value) {
    $this->actingAs($this->manager);

    expect(fn () => livewire(CreateDisposal::class)->set($property, $value))->toThrow(CannotUpdateLockedPropertyException::class);
})->with([
    'versions' => ['assetVersions', [1 => 999]],
    'ligne' => ['inventoryLineId', 7],
]);

it('prévient si la fiche a changé depuis le choix du bien (RG-86)', function () {
    $this->actingAs($this->manager);

    $page = livewire(CreateDisposal::class)->fillForm(disposalForm($this->asset));
    app(UpdateAsset::class)($this->asset->fresh(), ['designation' => 'Groupe électrogène révisé']);

    $page->callAction(confirmDisposal(), data: ['confirmation' => $this->asset->number])
        ->assertNotified('La fiche du bien a été modifiée entre-temps');

    expect($this->asset->fresh()->isDisposed())->toBeFalse();
});

it('liste les sorties du périmètre et propose « Valider » au seul comptable (E27)', function () {
    $disposal = recordDisposal($this->asset);
    $own = Department::factory()->create();
    $hidden = recordDisposal(createDepreciableAsset());

    $this->actingAs($this->accountant);
    livewire(ListDisposals::class)
        ->assertCanSeeTableRecords([$disposal, $hidden])
        ->assertActionVisible(TestAction::make('validateDisposal')->table($disposal))
        ->callAction(TestAction::make('validateDisposal')->table($disposal));

    expect($disposal->fresh()->isValidated())->toBeTrue();

    livewire(ViewDisposal::class, ['record' => $hidden->getRouteKey()])
        ->assertActionVisible('validateDisposal')
        ->callAction('validateDisposal')
        ->assertActionHidden('validateDisposal');

    expect($hidden->fresh()->isValidated())->toBeTrue();

    $restricted = User::factory()->role(DefaultRoles::MANAGER)->create();
    $restricted->departments()->attach($own);
    $this->actingAs($restricted);

    livewire(ListDisposals::class)->assertCanNotSeeTableRecords([$disposal, $hidden]);
    $this->get('/admin/sorties/'.$disposal->id)->assertNotFound();
});

it('cache « Valider » au gestionnaire et refuse l\'appel forcé', function () {
    $disposal = recordDisposal($this->asset);
    $this->actingAs($this->manager);

    livewire(ViewDisposal::class, ['record' => $disposal->getRouteKey()])
        ->assertActionHidden('validateDisposal')
        ->assertActionDoesNotExist(EditAction::class);

    livewire(ListDisposals::class)->assertActionHidden(TestAction::make('validateDisposal')->table($disposal));

    expect($disposal->fresh()->isValidated())->toBeFalse();
    $this->get('/admin/sorties/'.$disposal->id.'/edit')->assertNotFound();
});

it('propose « Sortir » sur la fiche d\'un bien, vers l\'écran prérempli', function () {
    $this->actingAs($this->manager);

    livewire(ViewAsset::class, ['record' => $this->asset->getRouteKey()])
        ->assertActionVisible('dispose')
        ->assertActionHasUrl('dispose', DisposalResource::getUrl('create', ['bien' => $this->asset->id]));

    Asset::query()->whereKey($this->asset->id)->update(['status' => AssetStatus::OnLoan->value]);

    livewire(ViewAsset::class, ['record' => $this->asset->getRouteKey()])->assertActionDisabled('dispose');

    $this->actingAs($this->accountant);
    livewire(ViewAsset::class, ['record' => $this->asset->getRouteKey()])->assertActionHidden('dispose');
});

it('présente la fiche du bien sorti en lecture seule, avec ses valeurs figées, dans tous les onglets (E43)', function () {
    recordDisposal($this->asset);
    $this->actingAs(User::factory()->admin()->create());

    livewire(ViewAsset::class, ['record' => $this->asset->getRouteKey()])
        ->assertSee('Sorti du patrimoine')
        ->assertSee('SRT-2026-001')
        ->assertSee(nbsp('2 180 000'))
        ->assertSee(nbsp('2 620 000'))
        ->assertActionHidden(EditAction::class)
        ->assertActionHidden('transfer')
        ->assertActionHidden('dispose');

    livewire(DocumentsRelationManager::class, ['ownerRecord' => $this->asset->fresh(), 'pageClass' => ViewAsset::class])
        ->assertActionHidden(TestAction::make('attach')->table());

    // Plan arrêté à la date de sortie (onglet et page du lot 7), sans « Modifier les paramètres ».
    livewire(AssetDepreciationSchedule::class, ['record' => $this->asset->getRouteKey()])
        ->assertActionHidden('changeParameters')
        ->assertSee('Sorti du patrimoine')
        ->assertSee(nbsp('493 333'));

    $this->get('/admin/biens/'.$this->asset->id.'/edit')->assertForbidden();
});

function confirmDisposal(): TestAction
{
    return TestAction::make('confirmDisposal')->schemaComponent('form-actions', schema: 'content');
}
