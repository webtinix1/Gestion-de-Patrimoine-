<?php

use App\Actions\Labels\PrintLabels;
use App\Enums\LabelCodeType;
use App\Enums\LabelSheetLayout;
use App\Filament\Pages\LabelPrinting;
use App\Filament\Resources\LabelTemplates\LabelTemplateResource;
use App\Filament\Resources\LabelTemplates\Pages\CreateLabelTemplate;
use App\Filament\Resources\LabelTemplates\Pages\EditLabelTemplate;
use App\Filament\Resources\LabelTemplates\Pages\ListLabelTemplates;
use App\Models\Activity;
use App\Models\Asset;
use App\Models\Department;
use App\Models\LabelTemplate;
use App\Models\User;
use App\Support\DefaultRoles;
use Database\Seeders\ReferenceSeeder;
use Filament\Facades\Filament;
use Illuminate\Auth\Access\AuthorizationException;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;

use function Pest\Livewire\livewire;

beforeEach(function () {
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    $this->manager = User::factory()->role(DefaultRoles::MANAGER)->create();
});

/** Un compte qui n'a que les droits d'étiquettes demandés. */
function userWithLabelRights(string ...$abilities): User
{
    $user = User::factory()->create();

    foreach ($abilities as $ability) {
        $user->givePermissionTo(Permission::findOrCreate('labels.'.$ability, 'web'));
    }

    return $user;
}

it('livre les formats de RG-96 : planche A4 3 × 8 de 70 × 37 mm et rouleau de 50 × 25 mm', function () {
    $this->seed(ReferenceSeeder::class);
    $this->seed(ReferenceSeeder::class);

    $sheet = LabelTemplate::query()->where('name', 'Standard')->firstOrFail();
    $roll = LabelTemplate::query()->where('name', 'Rouleau Code 128')->firstOrFail();

    expect(LabelTemplate::query()->count())->toBe(3)
        ->and($sheet->sheet_layout)->toBe(LabelSheetLayout::A4ThreeByEight)
        ->and([$sheet->width_mm, $sheet->height_mm])->toBe([70.0, 37.0])
        ->and($sheet->code_type)->toBe(LabelCodeType::Qr)
        ->and($sheet->is_default)->toBeTrue()
        ->and($roll->sheet_layout)->toBe(LabelSheetLayout::Roll)
        ->and([$roll->width_mm, $roll->height_mm])->toBe([50.0, 25.0])
        ->and($roll->code_type)->toBe(LabelCodeType::Code128)
        ->and($roll->is_default)->toBeTrue();
});

it('crée un modèle depuis l\'écran E10 avec un aperçu en direct', function () {
    $this->actingAs($this->manager);

    livewire(CreateLabelTemplate::class)
        ->assertSee('Aperçu en direct')
        ->assertSee('PAT-000125')
        ->fillForm([
            'name' => 'Mobilier',
            'sheet_layout' => LabelSheetLayout::Roll,
            'code_type' => LabelCodeType::Code128,
            'width_mm' => 50,
            'height_mm' => 25,
            'header_text' => 'ÉGLISE',
            'show_designation' => true,
            'show_department' => false,
        ])
        ->assertSee('data:image/svg+xml')
        ->assertSee('ÉGLISE')
        ->call('create')
        ->assertHasNoFormErrors();

    $template = LabelTemplate::query()->where('name', 'Mobilier')->firstOrFail();

    expect($template->code_type)->toBe(LabelCodeType::Code128)
        ->and($template->is_default)->toBeTrue();
});

it('adapte les dimensions proposées au support choisi', function () {
    $this->actingAs($this->manager);

    livewire(CreateLabelTemplate::class)
        ->fillForm(['sheet_layout' => LabelSheetLayout::Roll])
        ->assertSet('data.width_mm', 50.0)
        ->assertSet('data.height_mm', 25.0);
});

it('refuse un modèle qui dépasse la planche A4', function () {
    $this->actingAs($this->manager);

    livewire(CreateLabelTemplate::class)
        ->fillForm([
            'name' => 'Trop large',
            'sheet_layout' => LabelSheetLayout::A4ThreeByEight,
            'code_type' => LabelCodeType::Qr,
            'width_mm' => 80,
            'height_mm' => 37,
        ])
        ->call('create')
        ->assertHasFormErrors(['width_mm', 'height_mm' => []]);

    expect(LabelTemplate::query()->where('name', 'Trop large')->exists())->toBeFalse();
});

it('garde un seul modèle par défaut par type de support', function () {
    $this->actingAs($this->manager);

    $firstSheet = LabelTemplate::factory()->create(['name' => 'A', 'is_default' => true]);
    $roll = LabelTemplate::factory()->roll()->create(['name' => 'R', 'is_default' => true]);

    livewire(CreateLabelTemplate::class)
        ->fillForm([
            'name' => 'B',
            'sheet_layout' => LabelSheetLayout::A4ThreeByEight,
            'code_type' => LabelCodeType::Qr,
            'width_mm' => 70,
            'height_mm' => 37,
            'is_default' => true,
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    expect($firstSheet->fresh()->is_default)->toBeFalse()
        ->and(LabelTemplate::query()->where('name', 'B')->value('is_default'))->toBeTrue()
        ->and($roll->fresh()->is_default)->toBeTrue();
});

it('ne laisse pas un support sans modèle par défaut', function () {
    $this->actingAs($this->manager);

    $only = LabelTemplate::factory()->create(['name' => 'Seul', 'is_default' => true]);

    livewire(EditLabelTemplate::class, ['record' => $only->getRouteKey()])
        ->fillForm(['is_default' => false])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($only->fresh()->is_default)->toBeTrue();
});

it('modifie un modèle existant', function () {
    $this->actingAs($this->manager);

    $template = LabelTemplate::factory()->create(['name' => 'Ancien']);

    livewire(EditLabelTemplate::class, ['record' => $template->getRouteKey()])
        ->fillForm(['name' => 'Nouveau', 'show_site' => true])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($template->fresh()->name)->toBe('Nouveau')->and($template->fresh()->show_site)->toBeTrue();
});

it('liste les modèles avec leur format', function () {
    $this->actingAs($this->manager);

    $template = LabelTemplate::factory()->create(['name' => 'Standard']);

    livewire(ListLabelTemplates::class)
        ->assertCanSeeTableRecords([$template])
        ->assertSee('70 × 37 mm · QR code');
});

it('donne le droit de voir sans imprimer ni modifier avec « consulter »', function () {
    $viewer = userWithLabelRights('view');
    $this->actingAs($viewer);

    $template = LabelTemplate::factory()->create();

    expect($viewer->can('viewAny', LabelTemplate::class))->toBeTrue()
        ->and($viewer->can('print', LabelTemplate::class))->toBeFalse()
        ->and($viewer->can('create', LabelTemplate::class))->toBeFalse()
        ->and($viewer->can('update', $template))->toBeFalse();

    $this->get('/admin/etiquettes')->assertOk();
    $this->get('/admin/modeles-etiquettes')->assertOk();
    $this->get('/admin/modeles-etiquettes/create')->assertForbidden();
    $this->get("/admin/modeles-etiquettes/{$template->id}/edit")->assertForbidden();

    livewire(LabelPrinting::class)->assertActionHidden('generate');
});

it('donne le droit d\'imprimer avec « créer », sans gérer les modèles', function () {
    $printer = userWithLabelRights('view', 'create');
    $this->actingAs($printer);

    $template = LabelTemplate::factory()->create();
    $asset = Asset::factory()->create();

    expect($printer->can('print', LabelTemplate::class))->toBeTrue()
        ->and($printer->can('create', LabelTemplate::class))->toBeFalse();

    Livewire::withQueryParams(['biens' => (string) $asset->id])->test(LabelPrinting::class)
        ->assertActionVisible('generate')
        ->callAction('generate')
        ->assertFileDownloaded();

    expect(app(PrintLabels::class)($template, [$asset->id])->labelCount)->toBe(1);
    $this->get('/admin/modeles-etiquettes/create')->assertForbidden();
});

it('donne le droit de gérer les modèles avec « modifier »', function () {
    $editor = userWithLabelRights('view', 'update');
    $this->actingAs($editor);

    $template = LabelTemplate::factory()->create();

    $this->get('/admin/modeles-etiquettes/create')->assertOk();
    $this->get("/admin/modeles-etiquettes/{$template->id}/edit")->assertOk();
    expect($editor->can('print', LabelTemplate::class))->toBeFalse();
});

it('refuse l\'impression sans le droit, même en appelant directement l\'action', function () {
    $this->actingAs(userWithLabelRights('view'));

    app(PrintLabels::class)(LabelTemplate::factory()->create(), [Asset::factory()->create()->id]);
})->throws(AuthorizationException::class);

it('cache les étiquettes à qui n\'a aucun droit sur le module', function () {
    $this->actingAs(User::factory()->role(DefaultRoles::DEPARTMENT_HEAD)->create());

    $this->get('/admin/etiquettes')->assertForbidden();
    $this->get('/admin/modeles-etiquettes')->assertForbidden();
    expect(LabelTemplateResource::canViewAny())->toBeFalse();
});

it('ne supprime jamais un modèle d\'étiquette depuis l\'application', function () {
    $this->actingAs($this->manager);

    $template = LabelTemplate::factory()->create(['is_default' => true]);

    expect($this->manager->can('delete', $template))->toBeFalse()
        ->and($this->manager->can('deleteAny', LabelTemplate::class))->toBeFalse();
});

it('journalise la réimpression d\'un bien déjà étiqueté', function () {
    $this->actingAs($this->manager);

    $template = LabelTemplate::factory()->create(['name' => 'Standard']);
    $asset = Asset::factory()->create(['number' => 'PAT-000700']);

    app(PrintLabels::class)($template, [$asset->id]);
    app(PrintLabels::class)($template, [$asset->id], startCell: 3);

    $entries = Activity::query()->where('log_name', 'etiquettes')->orderBy('id')->get();

    expect($entries)->toHaveCount(2)
        ->and($entries[0]->description)->toBe('Impression d\'étiquettes : 1 étiquette, Planche A4, case 1')
        ->and($entries[0]->properties['attributes'])->not->toHaveKey('reprinted_numbers')
        ->and($entries[1]->description)->toBe('Réimpression d\'étiquettes : 1 étiquette, Planche A4, case 3')
        ->and($entries[1]->properties['attributes']['reprinted_numbers'])->toBe(['PAT-000700'])
        ->and($entries[1]->subject_id)->toBe($asset->id);
});

it('journalise une impression sur rouleau sans case', function () {
    $this->actingAs($this->manager);

    $roll = LabelTemplate::factory()->roll()->create(['name' => 'Rouleau']);
    $assets = Asset::factory()->count(2)->create();

    app(PrintLabels::class)($roll, $assets->pluck('id')->all(), copies: 2);

    $entry = Activity::query()->where('log_name', 'etiquettes')->firstOrFail();

    expect($entry->description)->toBe('Impression d\'étiquettes : 4 étiquettes, Rouleau 50 × 25 mm')
        ->and($entry->properties['attributes'])->not->toHaveKey('cells')
        ->and($entry->properties['attributes']['copies'])->toBe(2);
});

it('n\'imprime pas les biens hors périmètre ni les biens sortis, et ne les cite pas au journal', function () {
    $louange = Department::factory()->create();
    $jeunesse = Department::factory()->create();
    $own = Asset::factory()->create(['department_id' => $louange->id, 'number' => 'PAT-000801']);
    $other = Asset::factory()->create(['department_id' => $jeunesse->id, 'number' => 'PAT-000802']);
    $disposed = Asset::factory()->disposed()->create(['department_id' => $louange->id, 'number' => 'PAT-000803']);

    $limited = User::factory()->role(DefaultRoles::MANAGER)->create();
    $limited->departments()->attach($louange);
    $this->actingAs($limited);

    $printed = app(PrintLabels::class)(LabelTemplate::factory()->create(), [$other->id, $own->id, $disposed->id]);

    expect($printed->labelCount)->toBe(1)
        ->and(Activity::query()->where('log_name', 'etiquettes')->firstOrFail()->properties['attributes']['asset_numbers'])->toBe(['PAT-000801']);
});

it('distingue, bien par bien, la réimpression de la première impression', function () {
    $this->actingAs($this->manager);

    $template = LabelTemplate::factory()->create(['name' => 'Standard']);
    $old = Asset::factory()->create(['number' => 'PAT-000710']);
    $new = Asset::factory()->create(['number' => 'PAT-000711']);

    app(PrintLabels::class)($template, [$old->id]);
    app(PrintLabels::class)($template, [$old->id, $new->id]);

    $entry = Activity::query()->where('log_name', 'etiquettes')->latest('id')->firstOrFail();

    expect($entry->description)->toBe('Impression d\'étiquettes : 2 étiquettes, Planche A4, cases 1 à 2 (dont 1 réimpression)')
        ->and($entry->properties['attributes']['reprinted_numbers'])->toBe(['PAT-000710'])
        ->and($entry->subject_id)->toBeNull();
});

it('rattache l\'impression d\'un seul bien à sa fiche, onglet Journal compris', function () {
    $this->actingAs($this->manager);

    $asset = Asset::factory()->create();
    app(PrintLabels::class)(LabelTemplate::factory()->create(), [$asset->id]);

    $entry = Activity::query()->where('log_name', 'etiquettes')->firstOrFail();

    expect($entry->subject_type)->toBe($asset->getMorphClass())->and($entry->subject_id)->toBe($asset->id)
        ->and(Activity::query()->where('subject_type', $asset->getMorphClass())->where('subject_id', $asset->id)->where('log_name', 'etiquettes')->count())->toBe(1);
});

it('n\'écrit rien au journal quand le PDF ne peut pas être produit', function () {
    $this->actingAs($this->manager);

    $roll = LabelTemplate::factory()->roll()->create();
    $asset = Asset::factory()->create(['number' => 'AUD-2026-0000125-AB']);

    expect(fn () => app(PrintLabels::class)($roll, [$asset->id]))->toThrow(DomainException::class)
        ->and(Activity::query()->where('log_name', 'etiquettes')->count())->toBe(0);
});

it('ne laisse pas l\'autre support sans modèle par défaut quand on change le support du modèle par défaut', function () {
    $this->actingAs($this->manager);

    $sheetA = LabelTemplate::factory()->create(['name' => 'A', 'is_default' => true]);
    $sheetB = LabelTemplate::factory()->create(['name' => 'B']);
    $roll = LabelTemplate::factory()->roll()->create(['name' => 'R', 'is_default' => false]);

    livewire(EditLabelTemplate::class, ['record' => $sheetA->getRouteKey()])
        ->fillForm(['sheet_layout' => LabelSheetLayout::Roll, 'width_mm' => 50, 'height_mm' => 25, 'is_default' => true])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($sheetA->fresh()->is_default)->toBeTrue()
        ->and($roll->fresh()->is_default)->toBeFalse()
        ->and($sheetB->fresh()->is_default)->toBeTrue();
});

it('refuse à l\'enregistrement un modèle dont le numéro ne tient pas', function () {
    $this->actingAs($this->manager);

    livewire(CreateLabelTemplate::class)
        ->fillForm([
            'name' => 'Minuscule',
            'sheet_layout' => LabelSheetLayout::Roll,
            'code_type' => LabelCodeType::Qr,
            'width_mm' => 20,
            'height_mm' => 15,
        ])
        ->call('create')
        ->assertHasFormErrors(['width_mm']);

    expect(LabelTemplate::query()->where('name', 'Minuscule')->exists())->toBeFalse();
});
