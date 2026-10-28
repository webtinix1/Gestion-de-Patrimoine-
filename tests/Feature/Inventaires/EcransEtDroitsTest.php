<?php

use App\Actions\Inventories\CloseInventory;
use App\Actions\Inventories\DeclareRemainingMissing;
use App\Actions\Inventories\ExportInventoryReport;
use App\Actions\Inventories\RecordInventoryEntry;
use App\Actions\Inventories\SubmitInventory;
use App\Enums\InventoryLineResult;
use App\Enums\InventoryResolution;
use App\Enums\InventoryStatus;
use App\Exports\InventoryReportExport;
use App\Filament\Resources\Activities\Pages\ListActivities;
use App\Filament\Resources\Assets\Pages\ListAssets;
use App\Filament\Resources\Inventories\InventoryResource;
use App\Filament\Resources\Inventories\Pages\CreateInventory;
use App\Filament\Resources\Inventories\Pages\EnterInventory;
use App\Filament\Resources\Inventories\Pages\InventoryReportPage;
use App\Filament\Resources\Inventories\Pages\ListInventories;
use App\Models\Activity;
use App\Models\Inventory;
use App\Models\InventoryLine;
use App\Models\Room;
use App\Models\User;
use App\Services\Inventories\InventoryReport;
use App\Support\DefaultRoles;
use Illuminate\Support\Carbon;

use function Pest\Livewire\livewire;

require_once __DIR__.'/helpers.php';

beforeEach(function () {
    $this->w = inventoryWorld();
});

it('place « Inventaires » dans le groupe Opérations', function () {
    $this->actingAs($this->w->manager);

    $this->get('/admin/inventaires')->assertOk()->assertSee('Inventaires physiques');

    expect(InventoryResource::getNavigationGroup())->toBe('Opérations')
        ->and(InventoryResource::getNavigationSort())->toBe(5);
});

it('crée et démarre un inventaire depuis E19 (« Démarrer maintenant »)', function () {
    $this->actingAs($this->w->manager);

    livewire(CreateInventory::class)
        ->fillForm([
            'scope_type' => 'site',
            'scope_id' => $this->w->siege->id,
            'starts_on' => '2026-10-24',
            'ends_on' => '2026-10-28',
            'manager_id' => $this->w->manager->id,
            'participants' => [['user_id' => $this->w->headLouange->id, 'room_id' => $this->w->salleCulte->id]],
        ])
        ->call('createAndStart')
        ->assertHasNoFormErrors();

    $inventory = Inventory::query()->sole();

    expect($inventory->status)->toBe(InventoryStatus::InProgress)
        ->and(InventoryLine::query()->withoutGlobalScopes()->count())->toBe(6);
});

it('planifie un inventaire depuis E19 sans figer la liste', function () {
    $this->actingAs($this->w->manager);

    livewire(CreateInventory::class)
        ->fillForm(['scope_type' => 'all', 'starts_on' => '2026-11-03', 'manager_id' => $this->w->manager->id])
        ->call('create')
        ->assertHasNoFormErrors();

    expect(Inventory::query()->sole()->status)->toBe(InventoryStatus::Planned)
        ->and(InventoryLine::query()->withoutGlobalScopes()->count())->toBe(0);
});

it('saisit à la douchette depuis E20 : code + Entrée, compteurs à jour', function () {
    $inventory = siegeInventory($this->w);
    $this->actingAs($this->w->headLouange);

    livewire(EnterInventory::class, ['record' => $inventory->getKey()])
        ->assertSet('roomId', $this->w->salleCulte->id)
        ->set('code', 'PAT-000241')
        ->call('scan')
        ->assertSet('code', '')
        ->assertSet('lastOutcome.status', 'recorded')
        ->assertSee('présent, affectation conforme')
        ->call('mark', lineOf($inventory, $this->w->enceinte)->id, 'missing', lineOf($inventory, $this->w->enceinte)->lock_version)
        ->call('setCondition', lineOf($inventory, $this->w->bureauMeuble)->id, $this->w->aReparer->id, lineOf($inventory, $this->w->bureauMeuble)->lock_version)
        ->callAction('otherAssignment', ['room_id' => $this->w->bureau->id], ['line' => lineOf($inventory, $this->w->pret)->id, 'version' => lineOf($inventory, $this->w->pret)->lock_version])
        ->callAction('addUnregistered', ['description' => 'Ventilateur sur pied', 'site_id' => $this->w->siege->id, 'department_id' => $this->w->louange->id])
        ->assertSee('Ventilateur sur pied');

    expect(lineOf($inventory, $this->w->pupitre)->result)->toBe(InventoryLineResult::Present)
        ->and(lineOf($inventory, $this->w->enceinte)->result)->toBe(InventoryLineResult::Missing)
        ->and(lineOf($inventory, $this->w->bureauMeuble)->result)->toBe(InventoryLineResult::ConditionMismatch)
        ->and(lineOf($inventory, $this->w->pret)->result)->toBe(InventoryLineResult::OtherAssignment)
        ->and(lineOf($inventory, $this->w->pret)->checked_by)->toBe($this->w->headLouange->id);
});

it('prévient sans rien écraser quand une ligne a été pointée sur un autre poste (RG-47)', function () {
    $inventory = siegeInventory($this->w);
    $line = lineOf($inventory, $this->w->pupitre);
    $this->actingAs($this->w->headLouange);

    $page = livewire(EnterInventory::class, ['record' => $inventory->getKey()]);

    $this->actingAs($this->w->manager);
    app(RecordInventoryEntry::class)->scan($inventory, $this->w->manager, 'PAT-000241');

    $this->actingAs($this->w->headLouange);
    $page->call('mark', $line->id, 'missing', $line->lock_version)->assertNotified('Ligne pointée entre-temps');

    expect($line->fresh()->result)->toBe(InventoryLineResult::Present);
});

it('refuse la page de saisie par l\'URL directe à qui n\'est pas désigné (RG-82)', function () {
    $inventory = siegeInventory($this->w);
    $other = User::factory()->role(DefaultRoles::DEPARTMENT_HEAD)->create();
    $other->departments()->attach($this->w->louange);

    // Hors de son périmètre et non désigné : l'inventaire est introuvable pour lui.
    $this->actingAs($other)->get("/admin/inventaires/{$inventory->id}/saisie")->assertNotFound();
    $this->actingAs($this->w->accountant)->get("/admin/inventaires/{$inventory->id}/saisie")->assertForbidden();
    $this->actingAs($this->w->headLouange)->get("/admin/inventaires/{$inventory->id}/saisie")->assertOk();
    $this->actingAs($this->w->headLouange)->get("/admin/inventaires/{$inventory->id}/edit")->assertForbidden();
});

it('cache un inventaire hors périmètre, même par l\'URL directe (RG-81)', function () {
    $annexe = siegeInventory($this->w, ['scope_id' => $this->w->annexe->id, 'participants' => []]);
    $siege = siegeInventory($this->w);
    $siteManager = User::factory()->role(DefaultRoles::MANAGER)->create();
    $siteManager->sites()->attach($this->w->annexe);
    $this->actingAs($siteManager);

    livewire(ListInventories::class)->assertCanSeeTableRecords([$annexe])->assertCanNotSeeTableRecords([$siege]);

    $this->get("/admin/inventaires/{$siege->id}/rapport")->assertNotFound();
    $this->get("/admin/inventaires/{$siege->id}/saisie")->assertNotFound();
    $this->get("/admin/inventaires/{$annexe->id}/rapport")->assertOk();
});

it('montre à un responsable désigné l\'inventaire, limité aux biens de son périmètre (RG-81, RG-82)', function () {
    $inventory = siegeInventory($this->w);
    $this->actingAs($this->w->headLouange);

    livewire(ListInventories::class)->assertCanSeeTableRecords([$inventory]);

    livewire(EnterInventory::class, ['record' => $inventory->getKey()])
        ->assertSee('PAT-000241')
        ->assertDontSee('PAT-000306');
});

it('résiste à la manipulation Livewire : ligne hors périmètre ou d\'un autre inventaire, saisie inconnue (RG-81)', function () {
    $inventory = siegeInventory($this->w);
    $other = siegeInventory($this->w);
    $this->actingAs($this->w->headLouange);

    $jeune = lineOf($inventory, $this->w->jeune);
    $foreign = lineOf($other, $this->w->pupitre);

    livewire(EnterInventory::class, ['record' => $inventory->getKey()])
        ->call('mark', $jeune->id, 'missing', $jeune->lock_version)
        ->assertNotified('Saisie impossible')
        ->call('mark', $foreign->id, 'missing', $foreign->lock_version)
        ->call('mark', lineOf($inventory, $this->w->pupitre)->id, 'other_assignment', 0)
        ->call('setCondition', lineOf($inventory, $this->w->pupitre)->id, 999999, lineOf($inventory, $this->w->pupitre)->lock_version)
        ->set('roomId', $this->w->magasin->id)
        ->set('code', 'PAT-000241')
        ->call('scan')
        ->assertSet('lastOutcome.status', 'recorded');

    expect($jeune->fresh()->result)->toBe(InventoryLineResult::Pending)
        ->and($foreign->fresh()->result)->toBe(InventoryLineResult::Pending)
        ->and(lineOf($inventory, $this->w->pupitre)->result)->toBe(InventoryLineResult::OtherAssignment);

    // Un local hors du périmètre du responsable n'est pas accepté.
    $hidden = Room::factory()->create(['site_id' => $this->w->siege->id, 'department_id' => $this->w->jeunesse->id]);

    livewire(EnterInventory::class, ['record' => $inventory->getKey()])
        ->set('roomId', $hidden->id)
        ->set('code', 'PAT-000245')
        ->call('scan')
        ->assertSet('lastOutcome.status', 'error');

    expect(lineOf($inventory, $this->w->enceinte)->result)->toBe(InventoryLineResult::Pending);
});

it('refuse la saisie Livewire à un utilisateur retiré des participants', function () {
    $inventory = siegeInventory($this->w);
    $this->actingAs($this->w->headLouange);
    $page = livewire(EnterInventory::class, ['record' => $inventory->getKey()])->set('code', 'PAT-000241');

    $inventory->participants()->where('user_id', $this->w->headLouange->id)->delete();

    // Plus désigné et hors de son périmètre : l'inventaire lui devient introuvable.
    $page->call('scan')->assertNotFound();

    expect(lineOf($inventory, $this->w->pupitre)->result)->toBe(InventoryLineResult::Pending);
});

it('valide et clôture depuis E21, puis régularise depuis le rapport', function () {
    $inventory = siegeInventory($this->w);
    $record = app(RecordInventoryEntry::class);
    $this->actingAs($this->w->manager);
    $record->scan($inventory, $this->w->manager, 'PAT-000221', $this->w->salleCulte->id);
    $record->mark($inventory, $this->w->manager, lineOf($inventory, $this->w->pupitre)->id, 'missing', lineOf($inventory, $this->w->pupitre)->lock_version);
    app(SubmitInventory::class)($inventory, $this->w->manager);

    livewire(InventoryReportPage::class, ['record' => $inventory->getKey()])
        ->callAction('declareMissing')
        ->callAction('close', ['comment' => 'RAS', 'attested' => false])
        ->assertHasActionErrors(['attested']);

    livewire(InventoryReportPage::class, ['record' => $inventory->getKey()])
        ->callAction('close', ['comment' => 'Inventaire réalisé.', 'attested' => true])
        ->assertHasNoActionErrors();

    expect($inventory->fresh()->status)->toBe(InventoryStatus::Closed);

    livewire(InventoryReportPage::class, ['record' => $inventory->getKey()])
        ->assertSee('Biens manquants')
        ->callAction('transfer', [
            'site_id' => $this->w->siege->id, 'department_id' => $this->w->louange->id, 'room_id' => $this->w->salleCulte->id,
            'moved_on' => today()->toDateString(), 'reason' => 'Régularisation d\'inventaire',
        ], ['line' => lineOf($inventory, $this->w->bureauMeuble)->id])
        ->callAction('found', ['notes' => 'Retrouvé au magasin.'], ['line' => lineOf($inventory, $this->w->pupitre)->id]);

    expect(lineOf($inventory, $this->w->bureauMeuble)->resolution)->toBe(InventoryResolution::Transfer)
        ->and($this->w->bureauMeuble->fresh()->room_id)->toBe($this->w->salleCulte->id)
        ->and(lineOf($inventory, $this->w->pupitre)->resolution)->toBe(InventoryResolution::Found);
});

it('ne propose pas les régularisations à qui n\'en a pas le droit, ni avant la clôture', function () {
    $inventory = siegeInventory($this->w);
    app(RecordInventoryEntry::class)->scan($inventory, $this->w->manager, 'PAT-000221', $this->w->salleCulte->id);

    livewire(InventoryReportPage::class, ['record' => $inventory->getKey()])
        ->assertSee('Après la clôture')
        ->assertActionHidden('transfer');

    $this->actingAs($this->w->headLouange);

    livewire(InventoryReportPage::class, ['record' => $inventory->getKey()])
        ->assertActionHidden('close')
        ->assertActionHidden('exportPdf');
});

it('exporte le procès-verbal en PDF et le rapport en Excel (RG-49)', function () {
    Carbon::setTestNow(now()->startOfMinute());
    $inventory = siegeInventory($this->w);
    $this->actingAs($this->w->manager);

    livewire(InventoryReportPage::class, ['record' => $inventory->getKey()])
        ->callAction('exportPdf')
        ->assertFileDownloaded('inventaire-'.$inventory->reference.'-'.now()->format('Ymd-His').'.pdf');

    livewire(InventoryReportPage::class, ['record' => $inventory->getKey()])
        ->callAction('exportExcel')
        ->assertFileDownloaded('inventaire-'.$inventory->reference.'-'.now()->format('Ymd-His').'.xlsx');

    livewire(EnterInventory::class, ['record' => $inventory->getKey()])
        ->callAction('checklist')
        ->assertFileDownloaded('liste-controle-'.$inventory->reference.'.pdf');

    Carbon::setTestNow();
});

it('génère un PV avec les sections d\'écart et les emplacements de signature, sans ressource distante', function () {
    $inventory = siegeInventory($this->w);
    $this->actingAs($this->w->manager);
    app(RecordInventoryEntry::class)->mark($inventory, $this->w->manager, lineOf($inventory, $this->w->pupitre)->id, 'missing', lineOf($inventory, $this->w->pupitre)->lock_version);

    $html = view('pdf.inventory-report', [
        'inventory' => $inventory->load(['manager', 'participants.user', 'participants.room']),
        'counters' => app(InventoryReport::class)->counters($inventory),
        'sections' => app(InventoryReport::class)->sections($inventory),
        'observations' => collect(),
        'churchName' => 'Église', 'churchAcronym' => 'EG', 'editedAt' => now(),
    ])->render();

    expect($html)->toContain('Procès-verbal d\'inventaire · '.$inventory->reference)
        ->toContain('Biens manquants (1)')
        ->toContain('PAT-000241')
        ->toContain('Le responsable de l\'inventaire')
        ->not->toContain('http');

    expect(app(ExportInventoryReport::class)->render($inventory)->output())->toStartWith('%PDF');
});

it('limite les exports au périmètre de l\'utilisateur (RG-81)', function () {
    $inventory = siegeInventory($this->w);
    $viewer = User::factory()->role(DefaultRoles::VIEWER)->create();
    $viewer->departments()->attach($this->w->jeunesse);
    $inventory->participants()->create(['user_id' => $viewer->id]);
    $viewer->givePermissionTo('inventories.export');
    $this->actingAs($viewer);

    $lines = app(InventoryReport::class)->all($inventory);
    $export = new InventoryReportExport($inventory, app(InventoryReport::class)->counters($inventory), $lines);
    $numbers = collect($export->array())->slice(InventoryReportExport::HEADER_ROW)->pluck(0)->all();

    expect($numbers)->toBe(['PAT-000306']);
});

it('montre les entrées d\'inventaire au journal, filtrées par le périmètre (RG-70, RG-81)', function () {
    $inventory = siegeInventory($this->w);
    $record = app(RecordInventoryEntry::class);
    $record->scan($inventory, $this->w->manager, 'PAT-000241');
    $record->scan($inventory, $this->w->manager, 'PAT-000306');

    $auditor = User::factory()->role(DefaultRoles::ACCOUNTANT)->create();
    $auditor->departments()->attach($this->w->jeunesse);
    $this->actingAs($auditor);

    $visible = Activity::query()->where('event', 'inventory_line_recorded')->where('subject_id', $this->w->jeune->id)->get();
    $hidden = Activity::query()->where('event', 'inventory_line_recorded')->where('subject_id', $this->w->pupitre->id)->get();
    $start = Activity::query()->where('event', 'started')->get();

    livewire(ListActivities::class)
        ->assertCanSeeTableRecords($visible)
        ->assertCanNotSeeTableRecords($hidden)
        ->assertCanNotSeeTableRecords($start);
});

it('ajoute des biens à un inventaire planifié depuis la liste des biens (E05)', function () {
    $inventory = siegeInventory($this->w, ['scope_type' => 'department', 'scope_id' => $this->w->jeunesse->id], start: false);
    $this->actingAs($this->w->manager);

    livewire(ListAssets::class)
        ->callTableBulkAction('addToInventory', [$this->w->pupitre, $this->w->annexeBien], ['inventory_id' => $inventory->id])
        ->assertHasNoTableBulkActionErrors();

    expect(InventoryLine::query()->withoutGlobalScopes()->where('inventory_id', $inventory->id)->pluck('asset_id')->sort()->values()->all())
        ->toBe(collect([$this->w->pupitre->id, $this->w->annexeBien->id])->sort()->values()->all());
});

it('affiche le rapport d\'un inventaire clôturé à un responsable désigné', function () {
    $inventory = siegeInventory($this->w);
    $this->actingAs($this->w->manager);
    app(SubmitInventory::class)($inventory, $this->w->manager);
    app(DeclareRemainingMissing::class)($inventory, $this->w->manager);
    app(CloseInventory::class)($inventory, $this->w->manager, null, true);

    $this->actingAs($this->w->headLouange)->get("/admin/inventaires/{$inventory->id}/rapport")
        ->assertOk()
        ->assertSee('PAT-000241')
        ->assertDontSee('PAT-000306');
});
