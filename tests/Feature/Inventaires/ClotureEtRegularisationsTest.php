<?php

use App\Actions\Inventories\CloseInventory;
use App\Actions\Inventories\DeclareRemainingMissing;
use App\Actions\Inventories\RecordInventoryEntry;
use App\Actions\Inventories\RegularizeInventoryLine;
use App\Actions\Inventories\ReopenInventory;
use App\Actions\Inventories\ResolveInventoryLine;
use App\Actions\Inventories\SubmitInventory;
use App\Enums\InventoryLineResult;
use App\Enums\InventoryResolution;
use App\Enums\InventoryStatus;
use App\Exceptions\ConcurrentModificationException;
use App\Models\Activity;
use App\Models\Asset;
use App\Models\FiscalYear;
use App\Models\Inventory;
use App\Models\InventoryLine;
use App\Models\Movement;
use App\Services\FiscalYearClosing\AttentionPoint;
use App\Services\FiscalYearClosing\FiscalYearAttentionPoints;
use App\Services\MovementRecorder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Route;
use Illuminate\Validation\ValidationException;

require_once __DIR__.'/helpers.php';

/**
 * Inventaire saisi : pupitre manquant, meuble de bureau trouvé en salle de culte, enceinte à réparer,
 * bien de l'Annexe hors périmètre, un ventilateur non enregistré ; le bien Jeunesse reste à vérifier.
 */
function enteredInventory(object $w): Inventory
{
    $inventory = siegeInventory($w);
    $record = app(RecordInventoryEntry::class);

    $record->mark($inventory, $w->manager, lineOf($inventory, $w->pupitre)->id, 'missing');
    $record->scan($inventory, $w->manager, 'PAT-000221', $w->salleCulte->id);
    $record->mark($inventory, $w->manager, lineOf($inventory, $w->enceinte)->id, 'condition', ['condition_id' => $w->aReparer->id]);
    $record->scan($inventory, $w->manager, 'PAT-000700', $w->salleCulte->id);
    $record->addUnregistered($inventory, $w->manager, ['description' => 'Ventilateur sur pied', 'site_id' => $w->siege->id, 'department_id' => $w->louange->id, 'room_id' => $w->salleCulte->id, 'condition_id' => $w->bon->id]);

    return $inventory->fresh();
}

function closedInventory(object $w): Inventory
{
    $inventory = enteredInventory($w);
    app(RecordInventoryEntry::class)->scan($inventory, $w->manager, 'PAT-000306');
    app(SubmitInventory::class)($inventory, $w->manager);
    app(CloseInventory::class)($inventory, $w->manager, 'Inventaire réalisé avec les responsables.', true);

    return $inventory->fresh();
}

beforeEach(function () {
    $this->w = inventoryWorld();
    $this->actingAs($this->w->manager);
});

it('termine la saisie puis la rouvre avant la clôture', function () {
    $inventory = enteredInventory($this->w);

    app(SubmitInventory::class)($inventory, $this->w->manager);
    expect($inventory->fresh()->status)->toBe(InventoryStatus::ToValidate);

    app(ReopenInventory::class)($inventory, $this->w->manager);
    expect($inventory->fresh()->status)->toBe(InventoryStatus::InProgress);
});

it('refuse la clôture tant qu\'un bien attendu n\'a pas de résultat, ou sans attestation', function () {
    $inventory = enteredInventory($this->w);
    app(SubmitInventory::class)($inventory, $this->w->manager);

    expect(fn () => app(CloseInventory::class)($inventory, $this->w->manager, null, true))->toThrow(ValidationException::class, '1 bien attendu')
        ->and(fn () => app(CloseInventory::class)($inventory, $this->w->manager, null, false))->toThrow(ValidationException::class);

    expect(app(DeclareRemainingMissing::class)($inventory, $this->w->manager))->toBe(1)
        ->and(lineOf($inventory, $this->w->jeune)->result)->toBe(InventoryLineResult::Missing);

    app(CloseInventory::class)($inventory, $this->w->manager, null, true);
    expect($inventory->fresh()->status)->toBe(InventoryStatus::Closed);
});

it('réserve la validation et la clôture au droit « valider »', function () {
    $inventory = enteredInventory($this->w);
    app(SubmitInventory::class)($inventory, $this->w->manager);
    $this->actingAs($this->w->headLouange);

    expect(fn () => app(CloseInventory::class)($inventory, $this->w->headLouange, null, true))->toThrow(AuthorizationException::class)
        ->and(fn () => app(ReopenInventory::class)($inventory, $this->w->headLouange))->toThrow(AuthorizationException::class);
});

it('fige les résultats à la clôture sans modifier aucune fiche (RG-48)', function () {
    $before = Asset::query()->withoutGlobalScopes()->orderBy('id')->get()->map(fn (Asset $a) => $a->only(['id', 'lock_version', 'room_id', 'department_id', 'asset_condition_id', 'status', 'updated_at']))->all();
    $movements = Movement::query()->count();

    $inventory = closedInventory($this->w);

    $after = Asset::query()->withoutGlobalScopes()->orderBy('id')->get()->map(fn (Asset $a) => $a->only(['id', 'lock_version', 'room_id', 'department_id', 'asset_condition_id', 'status', 'updated_at']))->all();

    expect($inventory->status)->toBe(InventoryStatus::Closed)
        ->and($inventory->closed_by)->toBe($this->w->manager->id)
        ->and($inventory->closing_comment)->toBe('Inventaire réalisé avec les responsables.')
        ->and($after)->toEqual($before)
        ->and(Movement::query()->count())->toBe($movements);

    $line = lineOf($inventory, $this->w->pupitre);
    $line->forceFill(['result' => InventoryLineResult::Present]);

    expect(fn () => $line->save())->toThrow(LogicException::class)
        ->and(fn () => $inventory->forceFill(['notes' => 'x'])->save())->toThrow(LogicException::class)
        ->and(fn () => app(RecordInventoryEntry::class)->scan($inventory, $this->w->manager, 'PAT-000241'))->toThrow(ValidationException::class);
});

it('trace la clôture avec le résumé des résultats (RG-70)', function () {
    $inventory = closedInventory($this->w);
    $entry = Activity::query()->where('event', 'closed')->sole();

    expect($entry->subject_id)->toBe($inventory->id)
        ->and($entry->causer_id)->toBe($this->w->manager->id)
        ->and($entry->properties['attributes'])->toMatchArray(['statut' => 'Clôturé', 'manquant' => 1, 'autre affectation' => 1, 'état différent' => 1, 'non enregistré' => 1]);
});

it('régularise une autre affectation par un transfert tracé et lié à la ligne (RG-48, RG-21)', function () {
    $inventory = closedInventory($this->w);
    $line = lineOf($inventory, $this->w->bureauMeuble);

    app(RegularizeInventoryLine::class)->transfer($line, $this->w->manager, [
        'site_id' => $this->w->siege->id,
        'department_id' => $this->w->louange->id,
        'room_id' => $this->w->salleCulte->id,
        'moved_on' => today()->toDateString(),
    ], $this->w->bureauMeuble->lock_version);

    $movement = Movement::query()->where('asset_id', $this->w->bureauMeuble->id)->latest('id')->first();
    $line = $line->fresh();

    expect($this->w->bureauMeuble->fresh()->room_id)->toBe($this->w->salleCulte->id)
        ->and($movement->reason)->toBe('Régularisation d\'inventaire')
        ->and($movement->notes)->toContain($inventory->reference)
        ->and($movement->from_room_id)->toBe($this->w->bureau->id)
        ->and($line->resolution)->toBe(InventoryResolution::Transfer)
        ->and($line->resolution_ref_type)->toBe((new Movement)->getMorphClass())
        ->and($line->resolution_ref_id)->toBe($movement->id)
        ->and($line->resolved_by)->toBe($this->w->manager->id)
        ->and($line->result)->toBe(InventoryLineResult::OtherAssignment)
        ->and(Activity::query()->where('event', 'inventory_line_resolved')->where('subject_id', $this->w->bureauMeuble->id)->exists())->toBeTrue()
        ->and(fn () => app(RegularizeInventoryLine::class)->transfer($line, $this->w->manager, ['site_id' => $this->w->siege->id, 'department_id' => $this->w->jeunesse->id, 'moved_on' => today()->toDateString()]))
        ->toThrow(ValidationException::class);
});

it('régularise un état différent par la mise à jour de la fiche (RG-48, RG-86)', function () {
    $inventory = closedInventory($this->w);
    $line = lineOf($inventory, $this->w->enceinte);
    $version = $this->w->enceinte->fresh()->lock_version;

    app(RegularizeInventoryLine::class)->updateCondition($line, $this->w->manager, $version);

    expect($this->w->enceinte->fresh()->asset_condition_id)->toBe($this->w->aReparer->id)
        ->and($line->fresh()->resolution)->toBe(InventoryResolution::ConditionUpdate)
        ->and($line->fresh()->resolution_ref_id)->toBe($this->w->enceinte->id)
        ->and(Activity::query()->where('log_name', 'biens')->where('subject_id', $this->w->enceinte->id)->where('event', 'updated')->exists())->toBeTrue();
});

it('refuse la mise à jour d\'état si la fiche a changé depuis l\'ouverture (RG-86)', function () {
    $inventory = closedInventory($this->w);
    $line = lineOf($inventory, $this->w->enceinte);
    $version = $this->w->enceinte->fresh()->lock_version;
    $this->w->enceinte->fresh()->update(['notes' => 'modifiée ailleurs']);

    expect(fn () => app(RegularizeInventoryLine::class)->updateCondition($line, $this->w->manager, $version))
        ->toThrow(ConcurrentModificationException::class);

    expect($line->fresh()->resolution)->toBeNull();
});

it('crée la fiche d\'un bien non enregistré et la lie à la ligne (RG-46, RG-48)', function () {
    $inventory = closedInventory($this->w);
    $line = InventoryLine::query()->withoutGlobalScopes()->where('inventory_id', $inventory->id)->where('result', InventoryLineResult::Unregistered)->sole();

    app(RegularizeInventoryLine::class)->createAsset($line, $this->w->manager, [
        'designation' => 'Ventilateur sur pied',
        'asset_category_id' => $this->w->mobilier->id,
        'acquired_on' => '2026-10-28',
        'site_id' => $this->w->siege->id,
        'department_id' => $this->w->louange->id,
        'room_id' => $this->w->salleCulte->id,
        'asset_condition_id' => $this->w->bon->id,
        'status' => 'in_service',
    ]);

    $asset = Asset::query()->where('designation', 'Ventilateur sur pied')->sole();

    expect($line->fresh()->resolution)->toBe(InventoryResolution::CreatedAsset)
        ->and($line->fresh()->resolution_ref_id)->toBe($asset->id)
        ->and($asset->number)->toStartWith('PAT-')
        ->and(Movement::query()->where('asset_id', $asset->id)->count())->toBe(1);
});

it('note un bien manquant retrouvé après la clôture sans toucher au résultat figé', function () {
    $inventory = closedInventory($this->w);
    $line = lineOf($inventory, $this->w->pupitre);

    app(RegularizeInventoryLine::class)->markFound($line, $this->w->manager, 'Retrouvé à la paroisse le 03/11.');

    expect($line->fresh()->resolution)->toBe(InventoryResolution::Found)
        ->and($line->fresh()->resolution_notes)->toBe('Retrouvé à la paroisse le 03/11.')
        ->and($line->fresh()->result)->toBe(InventoryLineResult::Missing)
        ->and(fn () => app(RegularizeInventoryLine::class)->markFound($line, $this->w->manager, null))->toThrow(ValidationException::class);
});

it('refuse une régularisation avant la clôture ou inadaptée au résultat', function () {
    $inventory = enteredInventory($this->w);

    expect(fn () => app(RegularizeInventoryLine::class)->markFound(lineOf($inventory, $this->w->pupitre), $this->w->manager, null))->toThrow(AuthorizationException::class);

    $closed = closedInventory($this->w);

    expect(fn () => app(RegularizeInventoryLine::class)->markFound(lineOf($closed, $this->w->enceinte), $this->w->manager, null))->toThrow(ValidationException::class);
});

it('ne laisse régulariser que les droits de l\'opération concernée', function () {
    $inventory = closedInventory($this->w);
    $this->actingAs($this->w->headLouange);

    expect(fn () => app(RegularizeInventoryLine::class)->transfer(lineOf($inventory, $this->w->bureauMeuble), $this->w->headLouange, ['site_id' => $this->w->siege->id, 'department_id' => $this->w->louange->id, 'room_id' => $this->w->salleCulte->id, 'moved_on' => today()->toDateString()]))
        ->toThrow(AuthorizationException::class)
        ->and(fn () => app(RegularizeInventoryLine::class)->updateCondition(lineOf($inventory, $this->w->enceinte), $this->w->headLouange))
        ->toThrow(AuthorizationException::class);
});

it('fournit au lot 10 l\'action publique ResolveInventoryLine pour la sortie pour perte (contrat)', function () {
    $inventory = closedInventory($this->w);
    $line = lineOf($inventory, $this->w->pupitre);

    // Une sortie du lot 10 porte asset_id ; un mouvement en tient lieu ici.
    $wrong = Movement::query()->where('asset_id', $this->w->enceinte->id)->first() ?? app(MovementRecorder::class)->record($this->w->enceinte, null, ['site_id' => $this->w->siege->id, 'department_id' => $this->w->louange->id], today());
    $right = app(MovementRecorder::class)->record($this->w->pupitre, null, ['site_id' => $this->w->siege->id, 'department_id' => $this->w->louange->id], today());

    expect(fn () => app(ResolveInventoryLine::class)($line, InventoryResolution::Disposal, $wrong))->toThrow(ValidationException::class)
        ->and(fn () => app(ResolveInventoryLine::class)($line, 'transfer', $right))->toThrow(ValidationException::class)
        ->and(fn () => app(ResolveInventoryLine::class)($line, InventoryResolution::Disposal, null))->toThrow(ValidationException::class);

    app(ResolveInventoryLine::class)($line, 'disposal', $right);

    expect($line->fresh()->resolution)->toBe(InventoryResolution::Disposal)
        ->and($line->fresh()->resolution_ref_id)->toBe($right->id)
        ->and(fn () => app(ResolveInventoryLine::class)($line->fresh(), 'disposal', $right))->toThrow(ValidationException::class);
});

it('n\'affiche le lien de sortie pour perte que si la page des sorties existe (contrat lot 10)', function () {
    $inventory = closedInventory($this->w);

    expect(RegularizeInventoryLine::disposalUrl(lineOf($inventory, $this->w->pupitre)))->toBeNull();

    Route::get('admin/sorties/create', fn () => 'ok');

    expect(RegularizeInventoryLine::disposalUrl($line = lineOf($inventory, $this->w->pupitre)))
        ->toBe(url('/admin/sorties/create').'?bien='.$this->w->pupitre->id.'&motif=loss&inventaire_ligne='.$line->id);
});

it('signale les inventaires non clôturés avant la clôture de l\'exercice (RG-42)', function () {
    $year = FiscalYear::query()->create(['year' => 2026, 'label' => '2026', 'starts_on' => '2026-01-01', 'ends_on' => '2026-12-31', 'status' => 'open']);
    $inventory = enteredInventory($this->w);

    $point = collect(app(FiscalYearAttentionPoints::class)->for($year))->firstWhere('key', 'inventories');

    expect($point->severity)->toBe(AttentionPoint::WARNING)
        ->and($point->label)->toContain($inventory->reference);

    $this->w->jeune->refresh();
    app(RecordInventoryEntry::class)->scan($inventory, $this->w->manager, 'PAT-000306');
    app(SubmitInventory::class)($inventory, $this->w->manager);
    app(CloseInventory::class)($inventory, $this->w->manager, null, true);

    $point = collect(app(FiscalYearAttentionPoints::class)->for($year))->firstWhere('key', 'inventories');

    expect($point->severity)->toBe(AttentionPoint::OK);
});
