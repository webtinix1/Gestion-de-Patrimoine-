<?php

use App\Actions\Maintenance\CloseMaintenanceJob;
use App\Actions\Reports\CreateAssetReport;
use App\Actions\Reports\ProcessAssetReport;
use App\Enums\AssetStatus;
use App\Enums\MaintenanceResult;
use App\Enums\ReportStatus;
use App\Enums\ReportType;
use App\Enums\ReportUrgency;
use App\Filament\Resources\AssetReports\AssetReportResource;
use App\Filament\Resources\AssetReports\Pages\ListAssetReports;
use App\Filament\Resources\AssetReports\Pages\ViewAssetReport;
use App\Filament\Resources\Assets\AssetResource;
use App\Filament\Resources\Assets\Pages\ViewAsset;
use App\Filament\Resources\Maintenance\MaintenanceResource;
use App\Filament\Resources\Maintenance\Pages\CreateMaintenanceJob;
use App\Models\AssetReport;
use App\Models\MaintenanceJob;
use App\Models\Movement;
use App\Models\User;
use App\Support\DefaultRoles;
use Filament\Facades\Filament;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Tests\Support\OperationsWorld;

use function Pest\Livewire\livewire;

beforeEach(function () {
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    Storage::fake('local');

    $this->w = OperationsWorld::create();
    $this->asset = $this->w->asset();
    $this->head = $this->w->head($this->w->louange);
});

function screenReport(array $overrides = [], ?User $as = null, ?UploadedFile $photo = null): AssetReport
{
    test()->actingAs($as ?? test()->head);
    $report = app(CreateAssetReport::class)([
        'asset_id' => test()->asset->id,
        'type' => ReportType::Maintenance,
        'urgency' => ReportUrgency::Normal,
        'description' => 'Le canal B grésille puis coupe.',
        ...$overrides,
    ], $photo);
    test()->actingAs(test()->w->manager);

    return $report;
}

it('le responsable fait un signalement depuis la liste, sans modifier le bien (E24, RG-63)', function () {
    $this->actingAs($this->head);
    $version = $this->asset->lock_version;

    livewire(ListAssetReports::class)
        ->assertActionVisible('report')
        ->callAction('report', [
            'asset_id' => $this->asset->id, 'type' => 'condition', 'reported_condition_id' => $this->w->aReparer->id,
            'urgency' => 'urgent', 'description' => 'Ne démarre plus.',
        ])
        ->assertHasNoActionErrors()
        ->assertNotified();

    $report = AssetReport::query()->sole();

    expect($report->status)->toBe(ReportStatus::New)
        ->and($report->urgency)->toBe(ReportUrgency::Urgent)
        ->and($this->asset->fresh()->lock_version)->toBe($version)
        ->and($this->asset->fresh()->asset_condition_id)->toBe($this->w->bon->id);
});

it('le responsable signale depuis la fiche du bien, avec une photo (E25, RG-63)', function () {
    $this->actingAs($this->head);

    livewire(ViewAsset::class, ['record' => $this->asset->getRouteKey()])
        ->callAction('report', [
            'asset_id' => $this->asset->id, 'type' => 'maintenance', 'urgency' => 'normal', 'description' => 'Grésille.',
            'photo' => UploadedFile::fake()->image('canal.jpg'),
        ])
        ->assertHasNoActionErrors();

    $report = AssetReport::query()->sole();

    expect($report->photo_path)->not->toBeNull();
    Storage::disk('local')->assertExists($report->photo_path);
});

it('refuse, même par manipulation de la fenêtre, un signalement sur un bien d\'un autre département (RG-81)', function () {
    $foreign = $this->w->asset(['department_id' => $this->w->jeunesse->id]);
    $this->actingAs($this->head);

    livewire(ListAssetReports::class)
        ->callAction('report', [
            'asset_id' => $foreign->id, 'type' => 'maintenance', 'urgency' => 'normal', 'description' => 'Tentative hors périmètre.',
        ]);

    expect(AssetReport::query()->withoutGlobalScopes()->count())->toBe(0);

    // La fiche d'un bien d'un autre département n'est même pas accessible.
    livewire(ViewAsset::class, ['record' => $this->asset->getRouteKey()])
        ->callAction('report', [
            'asset_id' => $foreign->id, 'type' => 'maintenance', 'urgency' => 'normal', 'description' => 'Manipulation de la fenêtre.',
        ]);

    expect(AssetReport::query()->withoutGlobalScopes()->count())->toBe(0);
    $this->get(AssetResource::getUrl('view', ['record' => $foreign]))->assertNotFound();
});

it('le responsable voit ses signalements et leur statut, sans les actions du gestionnaire (E24, RG-64)', function () {
    $report = screenReport();
    $foreign = screenReport(['asset_id' => $this->w->asset(['department_id' => $this->w->jeunesse->id])->id], $this->w->head($this->w->jeunesse));
    app(ProcessAssetReport::class)->reject($report, 'Usure normale.');

    $this->actingAs($this->head);

    livewire(ListAssetReports::class)
        ->set('activeTab', 'all')
        ->assertCanSeeTableRecords([$report])
        ->assertCanNotSeeTableRecords([$foreign])
        ->assertSee('Rejeté')
        ->assertTableActionHidden('reject', $report)
        ->assertTableActionHidden('takeCharge', $report);

    livewire(ViewAssetReport::class, ['record' => $report->getRouteKey()])
        ->assertSee('Usure normale.')
        ->assertActionHidden('reject');

    $this->get(AssetReportResource::getUrl('view', ['record' => $report]))->assertOk();
    $this->get(AssetReportResource::getUrl('view', ['record' => $foreign]))->assertNotFound();
});

it('le gestionnaire rejette avec un motif obligatoire (E25, RG-64)', function () {
    $report = screenReport();
    $this->actingAs($this->w->manager);

    livewire(ListAssetReports::class)
        ->callTableAction('reject', $report, ['rejection_reason' => ''])
        ->assertHasTableActionErrors(['rejection_reason' => 'required']);

    expect($report->fresh()->status)->toBe(ReportStatus::New);

    livewire(ListAssetReports::class)
        ->callTableAction('reject', $report, ['rejection_reason' => 'Doublon du signalement précédent.'])
        ->assertHasNoTableActionErrors();

    expect($report->fresh()->status)->toBe(ReportStatus::Rejected)->and($report->fresh()->rejection_reason)->toBe('Doublon du signalement précédent.');
});

it('le gestionnaire prend en charge puis crée l\'intervention depuis le signalement (E25, RG-64)', function () {
    $report = screenReport(['type' => ReportType::Condition, 'reported_condition_id' => $this->w->aReparer->id]);
    $this->actingAs($this->w->manager);

    livewire(ViewAssetReport::class, ['record' => $report->getRouteKey()])
        ->assertActionVisible('takeCharge')
        ->assertActionVisible('createMaintenance')
        ->assertActionHasUrl('createMaintenance', MaintenanceResource::getUrl('create').'?'.http_build_query(['bien' => $this->asset->id, 'signalement' => $report->id]))
        ->callAction('takeCharge');

    expect($report->fresh()->status)->toBe(ReportStatus::InProgress);

    // Création de l'intervention depuis le signalement, puis clôture : le signalement passe Traité.
    Livewire\Livewire::withQueryParams(['bien' => $this->asset->id, 'signalement' => $report->id])
        ->test(CreateMaintenanceJob::class)
        ->fillForm([
            'asset_id' => $this->asset->id, 'nature' => 'repair', 'reason' => 'Signalement '.$report->reference, 'starts_on' => today()->toDateString(),
            'provider_type' => 'internal', 'put_in_maintenance' => true,
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $job = MaintenanceJob::query()->sole();

    expect($report->fresh()->handled_ref_id)->toBe($job->id)
        ->and($report->fresh()->status)->toBe(ReportStatus::InProgress);

    livewire(ViewAssetReport::class, ['record' => $report->getRouteKey()])->assertActionVisible('viewOperation')->assertActionHidden('createMaintenance');

    app(CloseMaintenanceJob::class)($job, ['result' => MaintenanceResult::Repaired, 'condition_after_id' => $this->w->bon->id, 'status_after' => AssetStatus::InService, 'ends_on' => today()->toDateString()]);

    expect($report->fresh()->status)->toBe(ReportStatus::Handled);

    $this->actingAs($this->head);
    livewire(ListAssetReports::class)->set('activeTab', 'handled')->assertCanSeeTableRecords([$report])->assertSee('Traité');
});

it('le gestionnaire traite un déplacement par un transfert (E25, RG-64)', function () {
    $report = screenReport(['type' => ReportType::Move, 'reported_department_id' => $this->w->jeunesse->id]);
    $this->actingAs($this->w->manager);

    livewire(ViewAssetReport::class, ['record' => $report->getRouteKey()])
        ->assertActionVisible('transfer')
        ->assertActionHidden('updateCondition')
        ->assertActionHidden('createMaintenance')
        ->mountAction('transfer')
        ->assertActionDataSet(['department_id' => $this->w->jeunesse->id, 'site_id' => $this->w->site->id])
        ->callMountedAction()
        ->assertHasNoActionErrors();

    expect($report->fresh()->status)->toBe(ReportStatus::Handled)
        ->and($this->asset->fresh()->department_id)->toBe($this->w->jeunesse->id)
        ->and(Movement::query()->where('asset_id', $this->asset->id)->count())->toBe(1);
});

it('le gestionnaire traite un changement d\'état par une mise à jour de la fiche (E25, RG-64)', function () {
    $report = screenReport(['type' => ReportType::Condition, 'reported_condition_id' => $this->w->aReparer->id]);
    $this->actingAs($this->w->manager);

    livewire(ViewAssetReport::class, ['record' => $report->getRouteKey()])
        ->assertActionVisible('updateCondition')
        ->assertActionHidden('transfer')
        ->callAction('updateCondition', ['condition_id' => $this->w->aReparer->id, 'asset_version' => $this->asset->lock_version])
        ->assertHasNoActionErrors();

    expect($report->fresh()->status)->toBe(ReportStatus::Handled)
        ->and($this->asset->fresh()->asset_condition_id)->toBe($this->w->aReparer->id);
});

it('une mise à jour avec une fiche modifiée entre-temps est refusée et le signalement reste ouvert (RG-86)', function () {
    $report = screenReport(['type' => ReportType::Condition, 'reported_condition_id' => $this->w->aReparer->id]);
    $this->actingAs($this->w->manager);
    $opened = $this->asset->lock_version;
    $this->asset->update(['notes' => 'Modifié ailleurs']);

    livewire(ViewAssetReport::class, ['record' => $report->getRouteKey()])
        ->callAction('updateCondition', ['condition_id' => $this->w->aReparer->id, 'asset_version' => $opened])
        ->assertNotified('Mise à jour impossible');

    expect($report->fresh()->status)->toBe(ReportStatus::New)
        ->and($this->asset->fresh()->asset_condition_id)->toBe($this->w->bon->id);
});

it('propose la sortie par le lien du lot 10 seulement quand la page existe (RG-64)', function () {
    $report = screenReport(['type' => ReportType::Condition, 'reported_condition_id' => $this->w->horsService->id, 'urgency' => ReportUrgency::Urgent]);
    $this->actingAs($this->w->manager);

    livewire(ViewAssetReport::class, ['record' => $report->getRouteKey()])->assertActionHidden('proposeDisposal');

    Route::get('admin/sorties/create', fn () => 'ok');

    livewire(ViewAssetReport::class, ['record' => $report->getRouteKey()])
        ->assertActionVisible('proposeDisposal')
        ->assertActionHasUrl('proposeDisposal', url('/admin/sorties/create').'?bien='.$this->asset->id.'&motif=retirement')
        ->assertActionHidden('markHandled')
        ->callAction('takeCharge');

    livewire(ViewAssetReport::class, ['record' => $report->getRouteKey()])
        ->assertActionVisible('markHandled')
        ->callAction('markHandled');

    expect($report->fresh()->status)->toBe(ReportStatus::Handled);
});

it('un signalement traité n\'offre plus aucune action', function () {
    $report = screenReport();
    app(ProcessAssetReport::class)->reject($report, 'Non fondé.');
    $this->actingAs($this->w->manager);

    livewire(ViewAssetReport::class, ['record' => $report->getRouteKey()])
        ->assertActionHidden('reject')
        ->assertActionHidden('takeCharge')
        ->assertActionHidden('createMaintenance')
        ->assertSee('Non fondé.');
});

it('un gestionnaire au périmètre restreint ne traite pas un signalement hors périmètre (RG-81)', function () {
    $report = screenReport();
    $this->actingAs($this->w->restrictedManager($this->w->jeunesse));

    livewire(ListAssetReports::class)->set('activeTab', 'all')->assertCanNotSeeTableRecords([$report]);
    $this->get(AssetReportResource::getUrl('view', ['record' => $report]))->assertNotFound();

    expect($report->fresh()->status)->toBe(ReportStatus::New);
});

it('sert la photo par une route contrôlée : propriétaire du périmètre oui, autre département non, sans droit non (RG-66)', function () {
    $report = screenReport(photo: UploadedFile::fake()->image('canal.jpg'));
    $route = route('filament.admin.reports.photo', ['report' => $report->id]);

    $this->actingAs($this->head)->get($route)->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff');
    $this->actingAs($this->w->manager)->get($route)->assertOk();
    $this->actingAs($this->w->head($this->w->jeunesse))->get($route)->assertNotFound();
    $this->actingAs(User::factory()->role(DefaultRoles::ACCOUNTANT)->create())->get($route)->assertForbidden();
    auth()->logout();
    $this->get($route)->assertRedirect();

    $withoutPhoto = screenReport();
    $this->actingAs($this->head)->get(route('filament.admin.reports.photo', ['report' => $withoutPhoto->id]))->assertNotFound();
});

it('le gestionnaire (matrice) peut traiter, le comptable et la consultation ne voient pas les signalements', function () {
    $report = screenReport();

    $this->actingAs(User::factory()->role(DefaultRoles::ACCOUNTANT)->create());
    $this->get(AssetReportResource::getUrl('index'))->assertForbidden();

    $this->actingAs(User::factory()->role(DefaultRoles::VIEWER)->create());
    $this->get(AssetReportResource::getUrl('index'))->assertOk();
    livewire(ViewAssetReport::class, ['record' => $report->getRouteKey()])->assertActionHidden('reject');
    livewire(ListAssetReports::class)->assertActionHidden('report');

    $this->actingAs($this->w->manager);
    livewire(ViewAssetReport::class, ['record' => $report->getRouteKey()])->assertActionVisible('reject');
});

it('la navigation Opérations range Mouvements, Prêts et emprunts, Maintenance, Signalements dans cet ordre', function () {
    $this->actingAs($this->w->manager);

    $group = collect(Filament::getNavigation())->first(fn ($group): bool => $group->getLabel() === 'Opérations');
    $labels = collect($group->getItems())->map->getLabel()->values()->all();

    expect(array_slice($labels, 0, 4))->toBe(['Mouvements', 'Prêts et emprunts', 'Maintenance', 'Signalements']);
});
