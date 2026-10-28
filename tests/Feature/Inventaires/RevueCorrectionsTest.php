<?php

use App\Actions\Inventories\CloseInventory;
use App\Actions\Inventories\DeclareRemainingMissing;
use App\Actions\Inventories\RecordInventoryEntry;
use App\Actions\Inventories\RegularizeInventoryLine;
use App\Actions\Inventories\ReopenInventory;
use App\Actions\Inventories\ResolveInventoryLine;
use App\Actions\Inventories\StartInventory;
use App\Actions\Inventories\SubmitInventory;
use App\Actions\Movements\TransferAssets;
use App\Enums\InventoryLineResult;
use App\Enums\InventoryResolution;
use App\Exceptions\ConcurrentModificationException;
use App\Exports\InventoryReportExport;
use App\Filament\Resources\Activities\ActivityResource;
use App\Filament\Resources\Inventories\Pages\InventoryReportPage;
use App\Models\Activity;
use App\Models\Asset;
use App\Models\Inventory;
use App\Models\InventoryLine;
use App\Models\Movement;
use App\Models\Room;
use App\Models\User;
use App\Services\Inventories\InventoryReport;
use App\Services\MovementRecorder;
use App\Support\DefaultRoles;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;

use function Pest\Livewire\livewire;

require_once __DIR__.'/helpers.php';

/*
 * Corrections après la revue indépendante du lot 9 (sondes transformées en tests).
 */

beforeEach(function () {
    $this->w = inventoryWorld();
    // Gestionnaire limité au département Louange, désigné participant d'un inventaire du Siège entier.
    $this->limited = User::factory()->role(DefaultRoles::MANAGER)->create(['first_name' => 'Limité', 'last_name' => 'Louange']);
    $this->limited->departments()->attach($this->w->louange);
});

function closeAll(Inventory $inventory, User $manager): Inventory
{
    app(SubmitInventory::class)($inventory, $manager);
    app(DeclareRemainingMissing::class)($inventory, $manager);
    app(CloseInventory::class)($inventory, $manager, null, true);

    return $inventory->fresh();
}

it('I-1 : une personne seulement désignée ne termine pas, ne déclare pas manquants, ne clôture ni ne rouvre l\'inventaire entier', function () {
    $inventory = siegeInventory($this->w, ['participants' => [['user_id' => $this->limited->id, 'room_id' => null]]]);
    foreach ([$this->w->pupitre, $this->w->enceinte, $this->w->bureauMeuble] as $asset) {
        app(RecordInventoryEntry::class)->scan($inventory, $this->w->manager, $asset->number);
    }

    $this->actingAs($this->limited);
    expect(fn () => app(SubmitInventory::class)($inventory, $this->limited))->toThrow(AuthorizationException::class);

    $this->actingAs($this->w->manager);
    app(SubmitInventory::class)($inventory, $this->w->manager);

    $this->actingAs($this->limited);
    expect(fn () => app(DeclareRemainingMissing::class)($inventory, $this->limited))->toThrow(AuthorizationException::class)
        ->and(fn () => app(CloseInventory::class)($inventory, $this->limited, null, true))->toThrow(AuthorizationException::class)
        ->and(fn () => app(ReopenInventory::class)($inventory, $this->limited))->toThrow(AuthorizationException::class)
        ->and(lineOf($inventory, $this->w->jeune)->result)->toBe(InventoryLineResult::Pending)
        ->and($inventory->fresh()->isClosed())->toBeFalse();

    livewire(InventoryReportPage::class, ['record' => $inventory->getKey()])
        ->assertActionHidden('close')
        ->assertActionHidden('declareMissing');
});

it('I-2 : chaque régularisation exige la bonne opération (classe, bien, date postérieure à la clôture)', function () {
    $inventory = siegeInventory($this->w);
    $record = app(RecordInventoryEntry::class);
    $record->mark($inventory, $this->w->manager, lineOf($inventory, $this->w->pupitre)->id, 'missing', lineOf($inventory, $this->w->pupitre)->lock_version);
    $record->scan($inventory, $this->w->manager, 'PAT-000221', $this->w->salleCulte->id);
    $record->mark($inventory, $this->w->manager, lineOf($inventory, $this->w->enceinte)->id, 'condition', lineOf($inventory, $this->w->enceinte)->lock_version, ['condition_id' => $this->w->aReparer->id]);
    $record->addUnregistered($inventory, $this->w->manager, ['description' => 'Objet A', 'site_id' => $this->w->siege->id, 'department_id' => $this->w->louange->id]);
    $record->addUnregistered($inventory, $this->w->manager, ['description' => 'Objet B', 'site_id' => $this->w->siege->id, 'department_id' => $this->w->louange->id]);
    $initial = app(MovementRecorder::class)->record($this->w->bureauMeuble, null, ['site_id' => $this->w->siege->id, 'department_id' => $this->w->louange->id], today());
    // Horodatages à la seconde : la clôture doit être nettement postérieure aux opérations d'avant.
    $this->travel(2)->seconds();
    $inventory = closeAll($inventory, $this->w->manager);
    $this->travel(2)->seconds();
    $resolve = app(ResolveInventoryLine::class);
    $unregistered = InventoryLine::query()->withoutGlobalScopes()->where('inventory_id', $inventory->id)->whereNull('asset_id')->orderBy('id')->get();

    // Sortie liée au bien lui-même, transfert lié au bien ou à l'affectation initiale, état lié à un autre bien.
    expect(fn () => $resolve(lineOf($inventory, $this->w->pupitre), InventoryResolution::Disposal, $this->w->pupitre))->toThrow(ValidationException::class)
        ->and(fn () => $resolve(lineOf($inventory, $this->w->bureauMeuble), InventoryResolution::Transfer, $this->w->bureauMeuble))->toThrow(ValidationException::class)
        ->and(fn () => $resolve(lineOf($inventory, $this->w->bureauMeuble), InventoryResolution::Transfer, $initial))->toThrow(ValidationException::class)
        ->and(fn () => $resolve(lineOf($inventory, $this->w->enceinte), InventoryResolution::ConditionUpdate, $this->w->pupitre))->toThrow(ValidationException::class)
        // Fiche « créée » qui existait avant la clôture.
        ->and(fn () => $resolve($unregistered[0], InventoryResolution::CreatedAsset, $this->w->annexeBien))->toThrow(ValidationException::class);

    // Une fiche créée après la clôture ne se lie qu'à une seule ligne.
    $new = Asset::factory()->create(['site_id' => $this->w->siege->id, 'department_id' => $this->w->louange->id]);
    $resolve($unregistered[0], InventoryResolution::CreatedAsset, $new);

    expect(fn () => $resolve($unregistered[1], InventoryResolution::CreatedAsset, $new))->toThrow(ValidationException::class, 'déjà liée');

    // Un vrai transfert après la clôture est accepté.
    $movement = app(TransferAssets::class)([$this->w->bureauMeuble], [
        'site_id' => $this->w->siege->id, 'department_id' => $this->w->louange->id, 'room_id' => $this->w->salleCulte->id, 'moved_on' => today()->toDateString(),
    ])->sole();
    $resolve(lineOf($inventory, $this->w->bureauMeuble), InventoryResolution::Transfer, $movement);

    expect(lineOf($inventory, $this->w->bureauMeuble)->resolution)->toBe(InventoryResolution::Transfer)
        ->and(lineOf($inventory, $this->w->pupitre)->resolution)->toBeNull();
});

it('M-1 : le responsable de département saisit mais ne régularise pas (QL9-7)', function () {
    $inventory = siegeInventory($this->w);
    app(RecordInventoryEntry::class)->mark($inventory, $this->w->manager, lineOf($inventory, $this->w->pupitre)->id, 'missing', lineOf($inventory, $this->w->pupitre)->lock_version);
    $inventory = closeAll($inventory, $this->w->manager);

    $this->actingAs($this->w->headLouange);

    expect(fn () => app(RegularizeInventoryLine::class)->markFound(lineOf($inventory, $this->w->pupitre), $this->w->headLouange, 'Retrouvé au grenier'))
        ->toThrow(AuthorizationException::class)
        ->and(lineOf($inventory, $this->w->pupitre)->resolution)->toBeNull();

    livewire(InventoryReportPage::class, ['record' => $inventory->getKey()])->assertActionHidden('found');

    // Un gestionnaire limité désigné sur un inventaire plus large ne régularise pas non plus.
    $inventory->participants()->create(['user_id' => $this->limited->id]);
    $this->actingAs($this->limited);

    expect(fn () => app(RegularizeInventoryLine::class)->markFound(lineOf($inventory, $this->w->pupitre), $this->limited, 'x'))
        ->toThrow(AuthorizationException::class);
});

it('M-2 : le journal d\'un bien non enregistré hors périmètre n\'est pas visible', function () {
    $inventory = siegeInventory($this->w, ['participants' => [['user_id' => $this->limited->id, 'room_id' => null]]]);
    app(RecordInventoryEntry::class)->addUnregistered($inventory, $this->w->manager, ['description' => 'Sono Jeunesse sans étiquette', 'site_id' => $this->w->siege->id, 'department_id' => $this->w->jeunesse->id]);
    app(RecordInventoryEntry::class)->addUnregistered($inventory, $this->w->manager, ['description' => 'Pupitre Louange sans étiquette', 'site_id' => $this->w->siege->id, 'department_id' => $this->w->louange->id]);

    $this->actingAs($this->limited);
    $descriptions = ActivityResource::getEloquentQuery()->where('log_name', 'inventaires')->get()
        ->map(fn (Activity $activity) => $activity->properties['attributes']['description'] ?? $activity->event)->all();

    expect($descriptions)->toContain('Pupitre Louange sans étiquette')
        ->not->toContain('Sono Jeunesse sans étiquette')
        // Démarrage et création de l'inventaire du Siège entier : hors de son périmètre.
        ->not->toContain('started')
        ->not->toContain('created');

    $this->actingAs($this->w->manager);
    expect(ActivityResource::getEloquentQuery()->where('event', 'started')->exists())->toBeTrue();
});

it('M-3 : un second démarrage reçoit le message « déjà démarré »', function () {
    $inventory = siegeInventory($this->w, start: false);
    $stale = Inventory::query()->find($inventory->id);
    app(StartInventory::class)($inventory, $this->w->manager);

    expect(fn () => app(StartInventory::class)($stale, $this->w->manager))->toThrow(ValidationException::class, 'déjà démarré');
});

it('M-4 : « autre affectation » vers le périmètre de l\'utilisateur ou l\'affectation attendue seulement (QL9-12)', function () {
    $inventory = siegeInventory($this->w);
    $this->actingAs($this->w->headLouange);
    $record = app(RecordInventoryEntry::class);
    $line = lineOf($inventory, $this->w->pupitre);
    $hidden = Room::factory()->create(['site_id' => $this->w->siege->id, 'department_id' => $this->w->jeunesse->id]);

    expect(fn () => $record->mark($inventory, $this->w->headLouange, $line->id, 'other_assignment', $line->lock_version, ['department_id' => $this->w->jeunesse->id]))
        ->toThrow(ValidationException::class)
        ->and(fn () => $record->mark($inventory, $this->w->headLouange, $line->id, 'other_assignment', $line->lock_version, ['room_id' => $hidden->id]))
        ->toThrow(ValidationException::class);

    $record->mark($inventory, $this->w->headLouange, $line->id, 'other_assignment', $line->lock_version, ['room_id' => $this->w->bureau->id]);

    expect($line->fresh()->result)->toBe(InventoryLineResult::OtherAssignment)
        ->and($line->fresh()->found_room_id)->toBe($this->w->bureau->id);

    // Le gestionnaire sans limite de périmètre peut constater n'importe quel département.
    $this->actingAs($this->w->manager);
    $jeune = lineOf($inventory, $this->w->jeune);
    $record->mark($inventory, $this->w->manager, $jeune->id, 'other_assignment', $jeune->lock_version, ['department_id' => $this->w->louange->id]);

    expect($jeune->fresh()->found_department_id)->toBe($this->w->louange->id);
});

it('M-5 : une ligne reste visible quand le bien attendu a été transféré hors du périmètre', function () {
    $inventory = siegeInventory($this->w);
    $this->w->pupitre->fresh()->forceFill(['department_id' => $this->w->jeunesse->id])->save();

    $this->actingAs($this->w->headLouange);

    expect(InventoryLine::query()->where('inventory_id', $inventory->id)->where('asset_id', $this->w->pupitre->id)->exists())->toBeTrue();

    $this->actingAs($this->w->headJeunesse);

    expect(InventoryLine::query()->where('inventory_id', $inventory->id)->where('asset_id', $this->w->pupitre->id)->exists())->toBeTrue();
});

it('M-6 : les exports d\'un utilisateur limité portent la mention du périmètre, et l\'Excel une colonne Site', function () {
    $inventory = siegeInventory($this->w);
    $this->actingAs($this->w->headLouange);
    $report = app(InventoryReport::class);

    $export = new InventoryReportExport($inventory, $report->counters($inventory), $report->all($inventory), InventoryReport::perimeterNote($this->w->headLouange));
    $rows = $export->array();

    expect($rows[3][0])->toContain('Extrait limité au périmètre de Emmanuel Mbemba')->toContain('Louange')
        ->and($rows[4][3])->toBe('Site')
        ->and($rows[5][3])->toBe('Siège')
        ->and(InventoryReport::perimeterNote($this->w->manager))->toBeNull();

    $html = view('pdf.inventory-report', [
        'inventory' => $inventory->load(['manager', 'participants.user', 'participants.room']),
        'counters' => $report->counters($inventory), 'sections' => $report->sections($inventory), 'observations' => collect(),
        'perimeterNote' => InventoryReport::perimeterNote($this->w->headLouange),
        'churchName' => 'Église', 'churchAcronym' => 'EG', 'editedAt' => now(),
    ])->render();

    expect($html)->toContain('Extrait limité au périmètre de Emmanuel Mbemba');
});

it('M-7 : une version périmée est refusée, y compris pour les régularisations', function () {
    $inventory = siegeInventory($this->w);
    app(RecordInventoryEntry::class)->scan($inventory, $this->w->manager, 'PAT-000221', $this->w->salleCulte->id);
    $inventory = closeAll($inventory, $this->w->manager);

    expect(fn () => app(RegularizeInventoryLine::class)->transfer(lineOf($inventory, $this->w->bureauMeuble), $this->w->manager, [
        'site_id' => $this->w->siege->id, 'department_id' => $this->w->louange->id, 'room_id' => $this->w->salleCulte->id, 'moved_on' => today()->toDateString(),
    ], -1))->toThrow(ConcurrentModificationException::class)
        ->and(Movement::query()->where('asset_id', $this->w->bureauMeuble->id)->count())->toBe(0);
});

it('journalise l\'état avant la première saisie d\'une ligne', function () {
    $inventory = siegeInventory($this->w);
    app(RecordInventoryEntry::class)->mark($inventory, $this->w->manager, lineOf($inventory, $this->w->pupitre)->id, 'missing', 0);
    $activity = Activity::query()->where('event', 'inventory_line_recorded')->latest('id')->first();

    expect($activity->properties['old'])->toBe(['résultat' => 'À vérifier'])
        ->and($activity->properties['attributes']['résultat'])->toBe('Manquant');
});
