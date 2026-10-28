<?php

use App\Actions\Inventories\RecordInventoryEntry;
use App\Actions\Inventories\SubmitInventory;
use App\Enums\InventoryLineResult;
use App\Exceptions\InventoryLineConflictException;
use App\Models\Activity;
use App\Models\InventoryLine;
use App\Models\User;
use App\Services\Inventories\InventoryReport;
use App\Services\Inventories\ScanOutcome;
use App\Support\DefaultRoles;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;

require_once __DIR__.'/helpers.php';

beforeEach(function () {
    $this->w = inventoryWorld();
    $this->inventory = siegeInventory($this->w);
    $this->record = app(RecordInventoryEntry::class);
    $this->actingAs($this->w->manager);
});

it('enregistre un bien scanné « présent » quand le local est conforme (RG-45)', function () {
    $outcome = $this->record->scan($this->inventory, $this->w->manager, ' pat-000241 ', $this->w->salleCulte->id);
    $line = lineOf($this->inventory, $this->w->pupitre);

    expect($outcome->status)->toBe(ScanOutcome::RECORDED)
        ->and($outcome->message)->toContain('présent, affectation conforme')
        ->and($line->result)->toBe(InventoryLineResult::Present)
        ->and($line->checked_by)->toBe($this->w->manager->id)
        ->and($line->found_room_id)->toBe($this->w->salleCulte->id)
        ->and($line->checked_at)->not->toBeNull();
});

it('enregistre « autre affectation » quand le bien est scanné dans un autre local (RG-45)', function () {
    $outcome = $this->record->scan($this->inventory, $this->w->manager, 'PAT-000221', $this->w->salleCulte->id);

    expect($outcome->status)->toBe(ScanOutcome::RECORDED)
        ->and($outcome->message)->toContain('Bureau du responsable')
        ->and(lineOf($this->inventory, $this->w->bureauMeuble)->result)->toBe(InventoryLineResult::OtherAssignment);
});

it('enregistre les boutons Présent, Manquant, Autre affectation et l\'état constaté (RG-45)', function () {
    $pupitre = lineOf($this->inventory, $this->w->pupitre);
    $enceinte = lineOf($this->inventory, $this->w->enceinte);
    $jeune = lineOf($this->inventory, $this->w->jeune);

    $this->record->mark($this->inventory, $this->w->manager, $pupitre->id, 'missing', $pupitre->lock_version);
    $this->record->mark($this->inventory, $this->w->manager, $enceinte->id, 'condition', $enceinte->lock_version, ['condition_id' => $this->w->aReparer->id], 'Ne s\'allume plus.');
    $this->record->mark($this->inventory, $this->w->manager, $jeune->id, 'other_assignment', $jeune->lock_version, ['department_id' => $this->w->louange->id]);

    expect($pupitre->fresh()->result)->toBe(InventoryLineResult::Missing)
        ->and($enceinte->fresh()->result)->toBe(InventoryLineResult::ConditionMismatch)
        ->and($enceinte->fresh()->found_condition_id)->toBe($this->w->aReparer->id)
        ->and($enceinte->fresh()->notes)->toBe('Ne s\'allume plus.')
        ->and($jeune->fresh()->result)->toBe(InventoryLineResult::OtherAssignment)
        ->and($jeune->fresh()->found_department_id)->toBe($this->w->louange->id);

    // Présent après « état différent » : le bien reste à un état différent ; état conforme : présent.
    $this->record->mark($this->inventory, $this->w->manager, $enceinte->id, 'present', $enceinte->fresh()->lock_version);
    expect($enceinte->fresh()->result)->toBe(InventoryLineResult::ConditionMismatch);

    $this->record->mark($this->inventory, $this->w->manager, $enceinte->id, 'condition', $enceinte->fresh()->lock_version, ['condition_id' => $this->w->bon->id]);
    expect($enceinte->fresh()->result)->toBe(InventoryLineResult::Present);

    // Le pupitre manquant est finalement retrouvé.
    $this->record->mark($this->inventory, $this->w->manager, $pupitre->id, 'present', $pupitre->fresh()->lock_version);
    expect($pupitre->fresh()->result)->toBe(InventoryLineResult::Present);
});

it('refuse une autre affectation identique à l\'affectation enregistrée', function () {
    $line = lineOf($this->inventory, $this->w->pupitre);

    expect(fn () => $this->record->mark($this->inventory, $this->w->manager, $line->id, 'other_assignment', $line->lock_version, ['department_id' => $this->w->louange->id, 'room_id' => $this->w->salleCulte->id]))
        ->toThrow(ValidationException::class);
});

it('enregistre « présent hors périmètre » un bien scanné hors de la liste, une seule fois (RG-46)', function () {
    $outcome = $this->record->scan($this->inventory, $this->w->manager, 'PAT-000700', $this->w->bureau->id);
    $again = $this->record->scan($this->inventory, $this->w->manager, 'PAT-000700', $this->w->bureau->id);
    $line = lineOf($this->inventory, $this->w->annexeBien);

    expect($outcome->status)->toBe(ScanOutcome::OUT_OF_SCOPE)
        ->and($again->status)->toBe(ScanOutcome::ALREADY)
        ->and($line->expected)->toBeFalse()
        ->and($line->result)->toBe(InventoryLineResult::OutOfScope)
        ->and($line->expected_site_id)->toBe($this->w->annexe->id)
        ->and($line->found_room_id)->toBe($this->w->bureau->id)
        ->and(InventoryLine::query()->withoutGlobalScopes()->where('asset_id', $this->w->annexeBien->id)->count())->toBe(1);
});

it('enregistre « présent hors périmètre » un bien sorti du patrimoine retrouvé sur place (RG-46)', function () {
    $outcome = $this->record->scan($this->inventory, $this->w->manager, 'PAT-000099');

    expect($outcome->status)->toBe(ScanOutcome::OUT_OF_SCOPE)
        ->and($outcome->message)->toContain('sorti du patrimoine');
});

it('enregistre un bien trouvé sans fiche comme « non enregistré » avec sa description (RG-46)', function () {
    $line = $this->record->addUnregistered($this->inventory, $this->w->manager, [
        'description' => 'Ventilateur sur pied Binatone',
        'site_id' => $this->w->siege->id,
        'department_id' => $this->w->louange->id,
        'room_id' => $this->w->salleCulte->id,
        'condition_id' => $this->w->bon->id,
    ]);

    expect($line->result)->toBe(InventoryLineResult::Unregistered)
        ->and($line->asset_id)->toBeNull()
        ->and($line->expected)->toBeFalse()
        ->and($line->description)->toBe('Ventilateur sur pied Binatone')
        ->and(fn () => $this->record->addUnregistered($this->inventory, $this->w->manager, ['description' => '', 'site_id' => $this->w->siege->id, 'department_id' => $this->w->louange->id]))
        ->toThrow(ValidationException::class)
        ->and(fn () => $this->record->addUnregistered($this->inventory, $this->w->manager, ['description' => 'Chaise', 'site_id' => $this->w->annexe->id, 'department_id' => $this->w->louange->id, 'room_id' => $this->w->salleCulte->id]))
        ->toThrow(ValidationException::class);
});

it('signale un code inconnu sans rien enregistrer', function () {
    $outcome = $this->record->scan($this->inventory, $this->w->manager, 'PAT-999999');

    expect($outcome->status)->toBe(ScanOutcome::UNKNOWN)
        ->and(InventoryLine::query()->withoutGlobalScopes()->where('inventory_id', $this->inventory->id)->count())->toBe(6);
});

it('n\'écrase jamais silencieusement une saisie : la douchette ne remplace pas un résultat différent (RG-47)', function () {
    $line = lineOf($this->inventory, $this->w->pupitre);
    $this->actingAs($this->w->headLouange);
    $this->record->mark($this->inventory, $this->w->headLouange, $line->id, 'missing', $line->lock_version);

    $this->actingAs($this->w->manager);
    $outcome = $this->record->scan($this->inventory, $this->w->manager, 'PAT-000241');

    expect($outcome->status)->toBe(ScanOutcome::CONFLICT)
        ->and($outcome->message)->toContain('Emmanuel Mbemba')
        ->and($line->fresh()->result)->toBe(InventoryLineResult::Missing)
        ->and($line->fresh()->checked_by)->toBe($this->w->headLouange->id);
});

it('ne laisse pas la douchette remplacer « justifié » (RG-45, RG-47)', function () {
    $outcome = $this->record->scan($this->inventory, $this->w->manager, 'PAT-000611');

    expect($outcome->status)->toBe(ScanOutcome::CONFLICT)
        ->and(lineOf($this->inventory, $this->w->pret)->result)->toBe(InventoryLineResult::Justified);
});

it('refuse un bouton appuyé sur une ligne pointée entre-temps par un autre poste (RG-47)', function () {
    $line = lineOf($this->inventory, $this->w->pupitre);
    $seenVersion = $line->lock_version;

    $this->record->scan($this->inventory, $this->w->manager, 'PAT-000241');

    $this->actingAs($this->w->headLouange);

    expect(fn () => $this->record->mark($this->inventory, $this->w->headLouange, $line->id, 'missing', $seenVersion))
        ->toThrow(InventoryLineConflictException::class, 'Albertine Okemba');

    expect($line->fresh()->result)->toBe(InventoryLineResult::Present);

    // Après rechargement, la correction est possible et tracée avec l'avant et l'après.
    $this->record->mark($this->inventory, $this->w->headLouange, $line->id, 'missing', $line->fresh()->lock_version);
    $last = Activity::query()->where('event', 'inventory_line_recorded')->latest('id')->first();

    expect($line->fresh()->result)->toBe(InventoryLineResult::Missing)
        ->and($last->causer_id)->toBe($this->w->headLouange->id)
        ->and($last->properties['old']['résultat'])->toBe('Présent')
        ->and($last->properties['attributes']['résultat'])->toBe('Manquant');
});

it('trace chaque saisie par bien et par utilisateur (RG-70)', function () {
    $this->record->scan($this->inventory, $this->w->manager, 'PAT-000241');
    $this->record->scan($this->inventory, $this->w->manager, 'PAT-000241');

    $entries = Activity::query()->where('event', 'inventory_line_recorded')->get();

    expect($entries)->toHaveCount(1)
        ->and($entries->first()->subject_id)->toBe($this->w->pupitre->id)
        ->and($entries->first()->causer_id)->toBe($this->w->manager->id)
        ->and($entries->first()->description)->toBe('Saisie d\'inventaire '.$this->inventory->reference)
        ->and($entries->first()->properties['attributes']['résultat'])->toBe('Présent');
});

it('tient les compteurs à jour (E20)', function () {
    $this->record->scan($this->inventory, $this->w->manager, 'PAT-000241');
    $this->record->scan($this->inventory, $this->w->manager, 'PAT-000221', $this->w->salleCulte->id);
    $this->record->mark($this->inventory, $this->w->manager, lineOf($this->inventory, $this->w->enceinte)->id, 'missing', lineOf($this->inventory, $this->w->enceinte)->lock_version);
    $this->record->scan($this->inventory, $this->w->manager, 'PAT-000700');
    $this->record->addUnregistered($this->inventory, $this->w->manager, ['description' => 'Ventilateur', 'site_id' => $this->w->siege->id, 'department_id' => $this->w->louange->id]);

    $counters = app(InventoryReport::class)->counters($this->inventory);

    expect($counters)->toMatchArray([
        'expected' => 6, 'present' => 2, 'missing' => 1, 'discrepancies' => 1, 'other_assignment' => 1,
        'unregistered' => 1, 'out_of_scope' => 1, 'justified' => 2, 'pending' => 1, 'checked' => 5, 'progress' => 83,
    ]);
});

it('ne laisse saisir que les personnes désignées et le gestionnaire (RG-47, RG-82)', function () {
    $other = User::factory()->role(DefaultRoles::DEPARTMENT_HEAD)->create();
    $other->departments()->attach($this->w->louange);
    $this->actingAs($other);

    expect(fn () => $this->record->scan($this->inventory, $other, 'PAT-000241'))->toThrow(AuthorizationException::class);

    $this->actingAs($this->w->accountant);
    expect(fn () => $this->record->scan($this->inventory, $this->w->accountant, 'PAT-000241'))->toThrow(AuthorizationException::class);

    $this->actingAs($this->w->headLouange);
    expect($this->record->scan($this->inventory, $this->w->headLouange, 'PAT-000241')->status)->toBe(ScanOutcome::RECORDED);
});

it('limite la saisie d\'un responsable aux biens de son périmètre (RG-81, RG-82)', function () {
    $this->actingAs($this->w->headLouange);
    $jeune = lineOf($this->inventory, $this->w->jeune);

    expect($this->record->scan($this->inventory, $this->w->headLouange, 'PAT-000306')->status)->toBe(ScanOutcome::UNKNOWN)
        ->and(fn () => $this->record->mark($this->inventory, $this->w->headLouange, $jeune->id, 'missing', $jeune->lock_version))->toThrow(ValidationException::class)
        ->and(fn () => $this->record->addUnregistered($this->inventory, $this->w->headLouange, ['description' => 'Chaise', 'site_id' => $this->w->siege->id, 'department_id' => $this->w->jeunesse->id]))
        ->toThrow(ValidationException::class)
        ->and($jeune->fresh()->result)->toBe(InventoryLineResult::Pending)
        ->and(app(InventoryReport::class)->counters($this->inventory)['expected'])->toBe(5);
});

it('refuse toute saisie quand l\'inventaire n\'est plus en cours', function () {
    app(SubmitInventory::class)($this->inventory, $this->w->manager);

    expect(fn () => $this->record->scan($this->inventory, $this->w->manager, 'PAT-000241'))->toThrow(ValidationException::class);
});
