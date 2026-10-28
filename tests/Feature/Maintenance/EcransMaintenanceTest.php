<?php

use App\Actions\Maintenance\CloseMaintenanceJob;
use App\Actions\Maintenance\OpenMaintenanceJob;
use App\Enums\AssetStatus;
use App\Enums\MaintenanceNature;
use App\Enums\MaintenanceResult;
use App\Enums\MaintenanceStatus;
use App\Enums\ProviderType;
use App\Filament\Resources\Assets\Pages\ViewAsset;
use App\Filament\Resources\Assets\RelationManagers\MaintenanceJobsRelationManager;
use App\Filament\Resources\Maintenance\MaintenanceResource;
use App\Filament\Resources\Maintenance\Pages\CreateMaintenanceJob;
use App\Filament\Resources\Maintenance\Pages\EditMaintenanceJob;
use App\Filament\Resources\Maintenance\Pages\ListMaintenanceJobs;
use App\Filament\Resources\Maintenance\Pages\ViewMaintenanceJob;
use App\Models\Asset;
use App\Models\MaintenanceJob;
use App\Support\DisposalUrl;
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

function screenJob(?Asset $asset = null, array $overrides = []): MaintenanceJob
{
    return app(OpenMaintenanceJob::class)([
        'asset_id' => ($asset ?? test()->w->asset())->id,
        'nature' => MaintenanceNature::Repair,
        'reason' => 'Embrayage qui patine',
        'starts_on' => today()->subDays(3)->toDateString(),
        'provider_type' => ProviderType::Provider,
        'provider_name' => 'Garage Mavoungou',
        'cost' => 650000,
        'put_in_maintenance' => true,
        ...$overrides,
    ]);
}

it('liste les interventions avec leurs filtres (E22)', function () {
    $ongoing = screenJob();
    $planned = screenJob(overrides: ['starts_on' => today()->addDays(8)->toDateString(), 'provider_type' => ProviderType::Internal, 'provider_name' => 'Informatique']);

    livewire(ListMaintenanceJobs::class)
        ->assertCanSeeTableRecords([$ongoing, $planned])
        ->filterTable('status', MaintenanceStatus::Planned->value)
        ->assertCanSeeTableRecords([$planned])
        ->assertCanNotSeeTableRecords([$ongoing])
        ->removeTableFilters()
        ->filterTable('provider_type', ProviderType::Provider->value)
        ->assertCanSeeTableRecords([$ongoing])
        ->assertCanNotSeeTableRecords([$planned])
        ->removeTableFilters()
        ->searchTable($ongoing->reference)
        ->assertCanSeeTableRecords([$ongoing])
        ->assertCanNotSeeTableRecords([$planned]);
});

it('ouvre une intervention depuis le formulaire et passe le bien En maintenance (E23, RG-60, RG-61)', function () {
    livewire(CreateMaintenanceJob::class)
        ->set('data.asset_id', $this->asset->id)
        ->fillForm([
            'nature' => 'repair',
            'reason' => 'Embrayage qui patine, révision des 150 000 km',
            'starts_on' => today()->toDateString(),
            'provider_type' => 'provider',
            'provider_name' => 'Garage Mavoungou',
            'cost' => 650000,
            'cost_reference' => 'Devis GM-D-2026-118',
            'put_in_maintenance' => true,
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $job = MaintenanceJob::query()->sole();

    expect($job->status)->toBe(MaintenanceStatus::Ongoing)
        ->and($job->cost)->toBe(650000)
        ->and($this->asset->fresh()->status)->toBe(AssetStatus::InMaintenance);
});

it('préselectionne le bien quand on arrive depuis sa fiche ou depuis un retour dégradé', function () {
    Livewire::withQueryParams(['bien' => $this->asset->id])
        ->test(CreateMaintenanceJob::class)
        ->assertFormSet(['asset_id' => $this->asset->id]);
});

it('refuse l\'ouverture quand la fiche a changé depuis la sélection du bien (RG-86)', function () {
    // La version est retenue côté serveur à la sélection du bien ; la fiche change ensuite.
    $page = livewire(CreateMaintenanceJob::class)->set('data.asset_id', $this->asset->id);
    $this->asset->update(['notes' => 'Modifié ailleurs']);

    $page
        ->fillForm([
            'nature' => 'repair', 'reason' => 'x', 'starts_on' => today()->toDateString(),
            'provider_type' => 'provider', 'put_in_maintenance' => true,
        ])
        ->call('create')
        ->assertNotified('Enregistrement impossible');

    expect(MaintenanceJob::query()->count())->toBe(0);
});

it('clôture l\'intervention avec état et statut, sans proposer de sortie si elle est réparée (E23, RG-61)', function () {
    $job = screenJob($this->asset);

    livewire(ViewMaintenanceJob::class, ['record' => $job->getRouteKey()])
        ->assertActionVisible('close')
        ->assertActionHidden('start')
        ->callAction('close', [
            'result' => 'repaired', 'condition_after_id' => $this->w->moyen->id, 'status_after' => 'in_stock', 'status_applies' => true,
            'ends_on' => today()->toDateString(), 'cost' => 700000,
        ])
        ->assertHasNoActionErrors()
        ->assertNotified('Intervention clôturée')
        ->assertActionHidden('proposeDisposal');

    expect($job->fresh()->status)->toBe(MaintenanceStatus::Done)
        ->and($this->asset->fresh()->status)->toBe(AssetStatus::InStock)
        ->and($this->asset->fresh()->asset_condition_id)->toBe($this->w->moyen->id);
});

it('refuse à la clôture un statut posé par une opération (RG-12), en gardant l\'intervention ouverte', function () {
    $job = screenJob($this->asset);

    livewire(ViewMaintenanceJob::class, ['record' => $job->getRouteKey()])
        ->callAction('close', [
            'result' => 'repaired', 'condition_after_id' => $this->w->bon->id, 'status_after' => 'on_loan', 'status_applies' => true, 'ends_on' => today()->toDateString(),
        ])
        ->assertHasActionErrors(['status_after']);

    expect($job->fresh()->status)->toBe(MaintenanceStatus::Ongoing)
        ->and($this->asset->fresh()->status)->toBe(AssetStatus::InMaintenance);
});

it('un résultat irréparable propose la sortie seulement si la page des sorties existe (RG-61)', function () {
    $job = screenJob($this->asset);

    if (DisposalUrl::pageExists()) {
        $this->markTestSkipped('La page des sorties (lot 10) existe : le cas « sans page » ne s\'applique pas.');
    }

    livewire(ViewMaintenanceJob::class, ['record' => $job->getRouteKey()])
        ->callAction('close', [
            'result' => 'irreparable', 'condition_after_id' => $this->w->horsService->id, 'status_after' => 'out_of_service', 'status_applies' => true, 'ends_on' => today()->toDateString(),
        ])
        ->assertNotified('Intervention clôturée')
        ->assertActionHidden('proposeDisposal');

    expect($job->fresh()->result)->toBe(MaintenanceResult::Irreparable);
});

it('propose la sortie par le lien du lot 10 quand la page existe', function () {
    OperationsWorld::simulateDisposalPage();
    $job = screenJob($this->asset);

    livewire(ViewMaintenanceJob::class, ['record' => $job->getRouteKey()])
        ->callAction('close', [
            'result' => 'irreparable', 'condition_after_id' => $this->w->horsService->id, 'status_after' => 'out_of_service', 'status_applies' => true, 'ends_on' => today()->toDateString(),
        ])
        ->assertNotified('Intervention clôturée');

    // À la réouverture de la fiche, l'intervention est irréparable et le lien vers la sortie est proposé.
    livewire(ViewMaintenanceJob::class, ['record' => $job->getRouteKey()])
        ->assertActionVisible('proposeDisposal')
        ->assertActionHasUrl('proposeDisposal', url('/admin/sorties/create').'?bien='.$this->asset->id.'&motif=retirement');

    expect(DisposalUrl::for($this->asset->id))->toBe(url('/admin/sorties/create').'?bien='.$this->asset->id.'&motif=retirement');
});

it('démarre une intervention planifiée (E23)', function () {
    $job = screenJob($this->asset, ['starts_on' => today()->addDays(5)->toDateString()]);

    livewire(ViewMaintenanceJob::class, ['record' => $job->getRouteKey()])
        ->assertActionVisible('start')
        ->assertActionHidden('close')
        ->callAction('start', ['put_in_maintenance' => true])
        ->assertNotified('Intervention démarrée');

    expect($job->fresh()->status)->toBe(MaintenanceStatus::Ongoing)
        ->and($this->asset->fresh()->status)->toBe(AssetStatus::InMaintenance);
});

it('modifie une intervention ouverte et prévient en cas de modification concurrente (RG-86)', function () {
    $job = screenJob($this->asset);

    $page = livewire(EditMaintenanceJob::class, ['record' => $job->getRouteKey()])
        ->assertFormSet(['reason' => 'Embrayage qui patine']);

    // Un autre utilisateur enregistre entre-temps.
    MaintenanceJob::query()->whereKey($job->id)->first()->update(['notes' => 'Autre utilisateur']);

    $page->fillForm(['reason' => 'Embrayage et boîte', 'cost' => 720000])
        ->call('save')
        ->assertNotified('Cette fiche a été modifiée entre-temps');

    expect($job->fresh()->reason)->toBe('Embrayage qui patine');

    livewire(EditMaintenanceJob::class, ['record' => $job->fresh()->getRouteKey()])
        ->fillForm(['reason' => 'Embrayage et boîte', 'cost' => 720000])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($job->fresh()->reason)->toBe('Embrayage et boîte')->and($job->fresh()->cost)->toBe(720000);
});

it('refuse la modification d\'une intervention terminée', function () {
    $job = screenJob($this->asset);
    app(CloseMaintenanceJob::class)($job, [
        'result' => MaintenanceResult::Repaired, 'condition_after_id' => $this->w->bon->id, 'status_after' => AssetStatus::InService, 'ends_on' => today()->toDateString(),
    ]);

    $this->get(MaintenanceResource::getUrl('edit', ['record' => $job]))->assertForbidden();
    livewire(ViewMaintenanceJob::class, ['record' => $job->getRouteKey()])->assertActionHidden('close')->assertActionHidden('edit');
});

it('le responsable de département consulte les interventions de son périmètre sans pouvoir agir (RG-81)', function () {
    $mine = screenJob($this->asset);
    $foreign = screenJob($this->w->asset(['department_id' => $this->w->jeunesse->id]));

    $this->actingAs($this->w->head($this->w->louange));

    livewire(ListMaintenanceJobs::class)
        ->assertCanSeeTableRecords([$mine])
        ->assertCanNotSeeTableRecords([$foreign]);

    livewire(ViewMaintenanceJob::class, ['record' => $mine->getRouteKey()])
        ->assertActionHidden('close')
        ->assertActionHidden('start')
        ->assertActionHidden('edit');

    $this->get(MaintenanceResource::getUrl('view', ['record' => $mine]))->assertOk();
    $this->get(MaintenanceResource::getUrl('view', ['record' => $foreign]))->assertNotFound();
    $this->get(MaintenanceResource::getUrl('edit', ['record' => $foreign]))->assertNotFound();
    $this->get(MaintenanceResource::getUrl('create'))->assertForbidden();
    $this->get(MaintenanceResource::getUrl('edit', ['record' => $mine]))->assertForbidden();
});

it('refuse de clôturer, par manipulation, l\'intervention d\'un bien hors du périmètre (RG-81)', function () {
    $foreign = screenJob($this->w->asset(['department_id' => $this->w->jeunesse->id]));
    $this->actingAs($this->w->restrictedManager($this->w->louange));

    livewire(ViewMaintenanceJob::class, ['record' => $foreign->getRouteKey()])->assertNotFound();

    expect($foreign->fresh()->status)->toBe(MaintenanceStatus::Ongoing);
});

it('l\'onglet Maintenance de la fiche du bien liste ses interventions (E06)', function () {
    $job = screenJob($this->asset);

    livewire(MaintenanceJobsRelationManager::class, ['ownerRecord' => $this->asset, 'pageClass' => ViewAsset::class])
        ->assertCanSeeTableRecords([$job])
        ->assertSee($job->reference);
});

it('le coût s\'affiche avec espaces dans les cartes de la liste (E22)', function () {
    screenJob($this->asset);

    livewire(ListMaintenanceJobs::class)->assertSee('650 000');
});
