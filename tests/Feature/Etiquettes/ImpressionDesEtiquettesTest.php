<?php

use App\Filament\Pages\LabelPrinting;
use App\Filament\Resources\Assets\Pages\ListAssets;
use App\Filament\Resources\Assets\Pages\ViewAsset;
use App\Models\Activity;
use App\Models\Asset;
use App\Models\Department;
use App\Models\LabelTemplate;
use App\Models\User;
use App\Support\DefaultRoles;
use Filament\Facades\Filament;
use Livewire\Livewire;

use function Pest\Livewire\livewire;

beforeEach(function () {
    Filament::setCurrentPanel(Filament::getPanel('admin'));

    $this->manager = User::factory()->role(DefaultRoles::MANAGER)->create();
    $this->actingAs($this->manager);

    $this->sheet = LabelTemplate::factory()->create(['name' => 'Standard', 'is_default' => true]);
    $this->roll = LabelTemplate::factory()->roll()->create(['name' => 'Rouleau Code 128', 'is_default' => true]);
    $this->assets = Asset::factory()->count(3)->sequence(
        ['number' => 'PAT-000501', 'designation' => 'Chaise'],
        ['number' => 'PAT-000502', 'designation' => 'Table'],
        ['number' => 'PAT-000503', 'designation' => 'Armoire'],
    )->create();
});

it('est dans le groupe Patrimoine, avec le slug etiquettes', function () {
    expect(LabelPrinting::getSlug())->toBe('etiquettes')
        ->and(LabelPrinting::getNavigationGroup())->toBe('Patrimoine')
        ->and(LabelPrinting::getNavigationLabel())->toBe('Étiquettes');

    $this->get('/admin/etiquettes')->assertOk()->assertSee('Impression des étiquettes');
});

it('présélectionne les biens de l\'adresse ?biens=12,13,14 (contrat avec le lot 5)', function () {
    $ids = $this->assets->pluck('id')->implode(',');

    $this->get("/admin/etiquettes?biens={$ids}")
        ->assertOk()
        ->assertSee('PAT-000501')
        ->assertSee('PAT-000502')
        ->assertSee('PAT-000503')
        ->assertSee('3 biens sélectionnés');

    Livewire::withQueryParams(['biens' => $ids])->test(LabelPrinting::class)
        ->assertSet('assetIds', $this->assets->pluck('id')->all());
});

it('ignore les identifiants invalides, doublons ou inconnus de l\'adresse', function () {
    $first = $this->assets[0]->id;

    Livewire::withQueryParams(['biens' => "{$first},abc,-4,{$first},999999,,0"])->test(LabelPrinting::class)
        ->assertSet('assetIds', [$first]);
});

it('ne retient ni les biens hors périmètre ni les biens sortis (RG-81)', function () {
    $louange = Department::factory()->create(['name' => 'Louange']);
    $jeunesse = Department::factory()->create(['name' => 'Jeunesse']);
    $own = Asset::factory()->create(['department_id' => $louange->id, 'number' => 'PAT-000601']);
    $other = Asset::factory()->create(['department_id' => $jeunesse->id, 'number' => 'PAT-000602']);
    $disposed = Asset::factory()->disposed()->create(['department_id' => $louange->id, 'number' => 'PAT-000603']);

    $limited = User::factory()->role(DefaultRoles::MANAGER)->create();
    $limited->departments()->attach($louange);
    $this->actingAs($limited);

    Livewire::withQueryParams(['biens' => "{$other->id},{$own->id},{$disposed->id}"])->test(LabelPrinting::class)
        ->assertSet('assetIds', [$own->id])
        ->call('addAsset', $other->id)
        ->assertSet('assetIds', [$own->id]);
});

it('ajoute un bien par son numéro, comme avec une douchette, et refuse un numéro inconnu', function () {
    livewire(LabelPrinting::class)
        ->fillForm(['scan' => ' pat-000502 '], 'selectionForm')
        ->call('addByNumber')
        ->assertSet('assetIds', [$this->assets[1]->id])
        ->fillForm(['scan' => 'PAT-009999'], 'selectionForm')
        ->call('addByNumber')
        ->assertSet('assetIds', [$this->assets[1]->id])
        ->assertNotified();
});

it('retire un bien de la sélection', function () {
    $ids = $this->assets->pluck('id')->all();

    Livewire::withQueryParams(['biens' => implode(',', $ids)])->test(LabelPrinting::class)
        ->call('removeAsset', $ids[1])
        ->assertSet('assetIds', [$ids[0], $ids[2]])
        ->call('clearAssets')
        ->assertSet('assetIds', []);
});

it('propose le modèle par défaut du support choisi', function () {
    livewire(LabelPrinting::class)
        ->assertSet('data.template_id', $this->sheet->id)
        ->fillForm(['support' => 'roll'])
        ->assertSet('data.template_id', $this->roll->id)
        ->assertSet('data.start_cell', 1);
});

it('montre l\'aperçu d\'une planche entamée : cases 5 à 7', function () {
    $ids = $this->assets->pluck('id')->implode(',');

    $component = Livewire::withQueryParams(['biens' => $ids])->test(LabelPrinting::class)
        ->fillForm(['start_cell' => 5]);

    $preview = $component->instance()->preview();

    expect($preview['info'])->toContain('1 planche A4')->toContain('3 étiquettes')->toContain('cases 5 à 7')
        ->and($preview['pages'])->toHaveCount(1)
        ->and(collect($preview['pages'][0])->filter(fn ($slot) => $slot->label !== null)->pluck('number')->all())->toBe([5, 6, 7])
        ->and($preview['pages'][0][4]->label->code->uri)->toStartWith('data:image/svg+xml');

    $component->assertSee('PAT-000501')->assertSee('cases 5 à 7');
});

it('montre l\'aperçu d\'un rouleau, une étiquette par page', function () {
    $ids = $this->assets->pluck('id')->implode(',');

    $preview = Livewire::withQueryParams(['biens' => $ids])->test(LabelPrinting::class)
        ->fillForm(['support' => 'roll'])
        ->instance()->preview();

    expect($preview['pages'])->toHaveCount(3)
        ->and($preview['width'])->toBe(50.0)
        ->and($preview['height'])->toBe(25.0)
        ->and($preview['info'])->toContain('3 étiquettes');
});

it('multiplie les étiquettes par le nombre de copies', function () {
    $preview = Livewire::withQueryParams(['biens' => (string) $this->assets[0]->id])->test(LabelPrinting::class)
        ->fillForm(['copies' => 4])
        ->instance()->preview();

    expect($preview['total'])->toBe(4);
});

it('génère le PDF et inscrit l\'impression au journal (RG-70)', function () {
    $ids = $this->assets->pluck('id')->implode(',');

    Livewire::withQueryParams(['biens' => $ids])->test(LabelPrinting::class)
        ->fillForm(['start_cell' => 5])
        ->callAction('generate')
        ->assertFileDownloaded();

    $entry = Activity::query()->where('log_name', 'etiquettes')->latest('id')->first();

    expect($entry)->not->toBeNull()
        ->and($entry->causer_id)->toBe($this->manager->id)
        ->and($entry->description)->toBe('Impression d\'étiquettes : 3 étiquettes, Planche A4, cases 5 à 7')
        ->and($entry->properties['attributes']['asset_numbers'])->toBe(['PAT-000501', 'PAT-000502', 'PAT-000503'])
        ->and($entry->properties['attributes']['template'])->toBe('Standard')
        ->and($entry->properties['attributes']['support'])->toBe('Planche A4')
        ->and($entry->properties['attributes']['cells'])->toBe('cases 5 à 7')
        ->and($entry->ip())->not->toBeNull();
});

it('refuse de générer sans bien sélectionné', function () {
    livewire(LabelPrinting::class)->assertActionDisabled('generate');
});

it('ajoute « Imprimer les étiquettes » en action groupée sur la liste des biens', function () {
    livewire(ListAssets::class)
        ->callTableBulkAction('printLabels', $this->assets)
        ->assertRedirect(LabelPrinting::getUrl(['biens' => $this->assets->reverse()->pluck('id')->implode(',')]));
});

it('ajoute « Imprimer l\'étiquette » sur la fiche d\'un bien', function () {
    livewire(ViewAsset::class, ['record' => $this->assets[0]->getRouteKey()])
        ->assertActionVisible('printLabel')
        ->assertActionHasUrl('printLabel', LabelPrinting::getUrl(['biens' => $this->assets[0]->id]));
});

it('n\'offre pas l\'étiquette d\'un bien sorti du patrimoine', function () {
    $disposed = Asset::factory()->disposed()->create();

    livewire(ViewAsset::class, ['record' => $disposed->getRouteKey()])
        ->assertActionHidden('printLabel');
});
