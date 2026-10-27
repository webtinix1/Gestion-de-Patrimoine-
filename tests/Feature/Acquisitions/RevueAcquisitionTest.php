<?php

use App\Actions\Acquisitions\AttachAcquisitionDocuments;
use App\Actions\Acquisitions\CreateAcquisition;
use App\Actions\Acquisitions\DetachAcquisitionDocument;
use App\Actions\Acquisitions\UpdateAcquisition;
use App\Actions\Acquisitions\ValidateAcquisition;
use App\Exceptions\ConcurrentModificationException;
use App\Filament\Resources\Acquisitions\Pages\EditAcquisition;
use App\Filament\Resources\Acquisitions\Pages\ListAcquisitions;
use App\Models\Acquisition;
use App\Models\Activity;
use App\Models\Asset;
use App\Models\AssetCategory;
use App\Models\AssetCondition;
use App\Models\Department;
use App\Models\Document;
use App\Models\DocumentType;
use App\Models\Site;
use App\Models\Supplier;
use App\Models\User;
use App\Support\DefaultRoles;
use Filament\Facades\Filament;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

use function Pest\Livewire\livewire;

beforeEach(function () {
    Storage::fake('local');
    Filament::setCurrentPanel(Filament::getPanel('admin'));

    $this->admin = User::factory()->admin()->create();
    $this->actingAs($this->admin);

    $this->site = Site::factory()->create();
    $this->louange = Department::factory()->create(['name' => 'Louange']);
    $this->jeunesse = Department::factory()->create(['name' => 'Jeunesse']);
    $this->supplier = Supplier::factory()->create();
    $this->category = AssetCategory::factory()->create(['prefix' => 'INF']);
    AssetCondition::factory()->create(['label' => 'Neuf', 'sort' => 1]);
    $this->type = DocumentType::factory()->create();
});

function revueData(array $overrides = []): array
{
    return [
        'acquired_on' => '2026-10-26',
        'supplier_id' => test()->supplier->id,
        'invoice_reference' => 'FAC-1',
        'site_id' => test()->site->id,
        'department_id' => test()->louange->id,
        'lines' => [['designation' => 'Portable', 'asset_category_id' => test()->category->id, 'quantity' => 2, 'unit_price' => 100000, 'tracked_individually' => true]],
        ...$overrides,
    ];
}

function revuePdf(): array
{
    return [UploadedFile::fake()->create('facture.pdf', 10, 'application/pdf')];
}

it('cache les justificatifs d\'acquisition à qui n\'a pas le droit de consulter les acquisitions', function () {
    $acquisition = app(CreateAcquisition::class)(revueData());
    app(AttachAcquisitionDocuments::class)(revuePdf(), $this->type, $acquisition);
    $document = Document::query()->firstOrFail();

    $head = User::factory()->role(DefaultRoles::DEPARTMENT_HEAD)->create();
    $head->departments()->attach($this->louange);
    $this->actingAs($head);

    expect(Document::query()->visible()->count())->toBe(0);
    $this->get($document->fileUrl())->assertNotFound();

    $manager = User::factory()->role(DefaultRoles::MANAGER)->create();
    $manager->departments()->attach($this->louange);
    $this->actingAs($manager);

    expect(Document::query()->visible()->count())->toBe(1);
    $this->get($document->fileUrl())->assertOk();

    $other = User::factory()->role(DefaultRoles::MANAGER)->create();
    $other->departments()->attach($this->jeunesse);
    $this->actingAs($other);

    expect(Document::query()->visible()->count())->toBe(0);
});

it('limite une acquisition à 500 unités suivies, les lignes non suivies ne comptent pas', function () {
    $line = fn (int $quantity, bool $tracked) => ['designation' => 'X', 'asset_category_id' => $this->category->id, 'quantity' => $quantity, 'unit_price' => 10, 'tracked_individually' => $tracked];

    expect(fn () => app(CreateAcquisition::class)(revueData(['lines' => [$line(300, true), $line(201, true)]])))
        ->toThrow(ValidationException::class, 'plus de 500 fiches');

    $ok = app(CreateAcquisition::class)(revueData(['lines' => [$line(300, true), $line(200, true), $line(500, false)]]));

    expect($ok->lines)->toHaveCount(3);
});

it('refuse aussi à la validation un brouillon de plus de 500 unités suivies', function () {
    $acquisition = Acquisition::factory()->create(['site_id' => $this->site->id, 'department_id' => $this->louange->id]);
    $acquisition->lines()->createMany([
        ['designation' => 'A', 'asset_category_id' => $this->category->id, 'quantity' => 300, 'unit_price' => 1, 'tracked_individually' => true, 'line_total' => 300],
        ['designation' => 'B', 'asset_category_id' => $this->category->id, 'quantity' => 201, 'unit_price' => 1, 'tracked_individually' => true, 'line_total' => 201],
    ]);

    expect(fn () => app(ValidateAcquisition::class)($acquisition))->toThrow(DomainException::class, '500 fiches')
        ->and(Asset::query()->count())->toBe(0);
});

it('refuse d\'ajouter ou de détacher un justificatif avec un modèle périmé', function () {
    $acquisition = app(CreateAcquisition::class)(revueData());
    $document = app(AttachAcquisitionDocuments::class)(revuePdf(), $this->type, $acquisition)->first();

    $stale = Acquisition::query()->findOrFail($acquisition->id);
    app(ValidateAcquisition::class)(Acquisition::query()->findOrFail($acquisition->id));

    expect($stale->isDraft())->toBeTrue()
        ->and(fn () => app(AttachAcquisitionDocuments::class)(revuePdf(), $this->type, $stale))->toThrow(DomainException::class)
        ->and(fn () => app(DetachAcquisitionDocument::class)($document, $stale))->toThrow(DomainException::class)
        ->and($document->acquisitions()->count())->toBe(1)
        ->and(Document::query()->count())->toBe(1);
});

it('refuse de valider deux fois avec un modèle périmé', function () {
    $acquisition = app(CreateAcquisition::class)(revueData());
    $stale = Acquisition::query()->findOrFail($acquisition->id);

    app(ValidateAcquisition::class)($acquisition);

    expect($stale->isDraft())->toBeTrue()
        ->and(fn () => app(ValidateAcquisition::class)($stale))->toThrow(DomainException::class)
        ->and(Asset::query()->count())->toBe(2);
});

it('ne propose pas la validation sur l\'écran de modification', function () {
    $acquisition = app(CreateAcquisition::class)(revueData());

    livewire(EditAcquisition::class, ['record' => $acquisition->getRouteKey()])
        ->assertActionDoesNotExist('validate');
});

it('détecte deux modifications simultanées d\'un brouillon (RG-86)', function () {
    $acquisition = app(CreateAcquisition::class)(revueData());
    $version = $acquisition->fresh()->lock_version;

    app(UpdateAcquisition::class)($acquisition, revueData(['invoice_reference' => 'FAC-AUTRE']), $version);

    expect($acquisition->fresh()->lock_version)->toBe($version + 1)
        ->and(fn () => app(UpdateAcquisition::class)($acquisition, revueData(['invoice_reference' => 'FAC-ECRASE']), $version))
        ->toThrow(ConcurrentModificationException::class)
        ->and($acquisition->fresh()->invoice_reference)->toBe('FAC-AUTRE');
});

it('prévient l\'utilisateur dont l\'enregistrement arrive après celui d\'un autre (RG-86)', function () {
    $acquisition = app(CreateAcquisition::class)(revueData());

    $page = livewire(EditAcquisition::class, ['record' => $acquisition->getRouteKey()]);

    app(UpdateAcquisition::class)($acquisition, revueData(['invoice_reference' => 'FAC-AUTRE']));

    $page->fillForm(['invoice_reference' => 'FAC-ECRASE'])
        ->call('save')
        ->assertNotified('Cette fiche a été modifiée entre-temps');

    expect($acquisition->fresh()->invoice_reference)->toBe('FAC-AUTRE');
});

it('enregistre un brouillon modifié depuis l\'écran quand personne d\'autre n\'est passé', function () {
    $acquisition = app(CreateAcquisition::class)(revueData());

    livewire(EditAcquisition::class, ['record' => $acquisition->getRouteKey()])
        ->fillForm(['invoice_reference' => 'FAC-NOUVELLE'])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($acquisition->fresh()->invoice_reference)->toBe('FAC-NOUVELLE');
});

it('limite les filtres site et département au périmètre', function () {
    $user = User::factory()->role(DefaultRoles::MANAGER)->create();
    $user->departments()->attach($this->louange);
    $this->actingAs($user);

    livewire(ListAcquisitions::class)->assertSee('Louange')
        ->assertDontSee('Jeunesse');
});

it('refuse un fournisseur désactivé', function () {
    $inactive = Supplier::factory()->inactive()->create();

    expect(fn () => app(CreateAcquisition::class)(revueData(['supplier_id' => $inactive->id])))->toThrow(ValidationException::class);
});

it('trace la modification des lignes et de l\'en-tête avec l\'avant et l\'après (RG-70)', function () {
    $acquisition = app(CreateAcquisition::class)(revueData());

    app(UpdateAcquisition::class)($acquisition, revueData([
        'invoice_reference' => 'FAC-2',
        'lines' => [['designation' => 'Portable', 'asset_category_id' => $this->category->id, 'quantity' => 3, 'unit_price' => 100000, 'tracked_individually' => true]],
    ]));

    $log = Activity::query()->where('log_name', 'acquisitions')->where('event', 'updated')->firstOrFail();

    expect($log->properties['old']['facture'])->toBe('FAC-1')
        ->and($log->properties['attributes']['facture'])->toBe('FAC-2')
        ->and($log->properties['old']['lignes'][0])->toContain('2 × ')
        ->and($log->properties['attributes']['lignes'][0])->toContain('3 × ')
        ->and($log->causer_id)->toBe($this->admin->id);

    app(UpdateAcquisition::class)($acquisition, revueData([
        'invoice_reference' => 'FAC-2',
        'lines' => [['designation' => 'Portable', 'asset_category_id' => $this->category->id, 'quantity' => 3, 'unit_price' => 100000, 'tracked_individually' => true]],
    ]));

    expect(Activity::query()->where('log_name', 'acquisitions')->where('event', 'updated')->count())->toBe(1);
});

it('interdit de changer l\'année de la date d\'un brouillon, la référence la porte', function () {
    $acquisition = app(CreateAcquisition::class)(revueData());

    expect(fn () => app(UpdateAcquisition::class)($acquisition, revueData(['acquired_on' => '2025-12-31'])))
        ->toThrow(ValidationException::class, 'ACQ-2026-001');

    app(UpdateAcquisition::class)($acquisition, revueData(['acquired_on' => '2026-01-05']));

    expect($acquisition->fresh()->acquired_on->toDateString())->toBe('2026-01-05');
});
