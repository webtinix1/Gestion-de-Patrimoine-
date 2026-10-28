<?php

use App\Actions\Acquisitions\AttachAcquisitionDocuments;
use App\Actions\Acquisitions\CreateAcquisition;
use App\Actions\Acquisitions\DeleteAcquisition;
use App\Actions\Acquisitions\UpdateAcquisition;
use App\Actions\Acquisitions\ValidateAcquisition;
use App\Enums\AcquisitionStatus;
use App\Enums\AssetStatus;
use App\Enums\Depreciability;
use App\Filament\Resources\Acquisitions\Pages\EditAcquisition;
use App\Filament\Resources\Acquisitions\Pages\ListAcquisitions;
use App\Filament\Resources\Acquisitions\Pages\ViewAcquisition;
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
use App\Support\LabelsUrl;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

use function Pest\Livewire\livewire;

beforeEach(function () {
    Storage::fake('local');
    Filament::setCurrentPanel(Filament::getPanel('admin'));

    $this->admin = User::factory()->admin()->create();
    $this->actingAs($this->admin);

    $this->site = Site::factory()->create();
    $this->department = Department::factory()->create();
    $this->supplier = Supplier::factory()->create();
    $this->condition = AssetCondition::factory()->create(['label' => 'Neuf', 'sort' => 1]);
    $this->computers = AssetCategory::factory()->create([
        'name' => 'Matériel informatique', 'prefix' => 'INF', 'depreciable' => Depreciability::Yes,
        'default_duration_years' => 3, 'asset_account' => '2441', 'depreciation_account' => '28441',
    ]);
    $this->electric = AssetCategory::factory()->create([
        'name' => 'Matériel électrique', 'prefix' => 'ELE', 'depreciable' => Depreciability::Yes,
        'default_duration_years' => 8,
    ]);
    $this->misc = AssetCategory::factory()->create(['name' => 'Autres équipements', 'prefix' => 'AUT', 'depreciable' => Depreciability::No]);
});

function draftAcquisition(?array $lines = null, array $overrides = []): Acquisition
{
    return app(CreateAcquisition::class)([
        'acquired_on' => '2026-10-26',
        'supplier_id' => test()->supplier->id,
        'invoice_reference' => 'FAC-2026-1003',
        'funding_source' => 'grant',
        'site_id' => test()->site->id,
        'department_id' => test()->department->id,
        'lines' => $lines ?? [
            ['designation' => 'Ordinateur portable HP ProBook 450', 'asset_category_id' => test()->computers->id, 'quantity' => 3, 'unit_price' => 600000, 'tracked_individually' => true],
            ['designation' => 'Onduleur APC 1500 VA', 'asset_category_id' => test()->electric->id, 'quantity' => 3, 'unit_price' => 145000, 'tracked_individually' => true],
            ['designation' => 'Câbles HDMI 3 m', 'asset_category_id' => test()->misc->id, 'quantity' => 6, 'unit_price' => 7500, 'tracked_individually' => false],
        ],
        ...$overrides,
    ]);
}

it('crée une fiche par unité suivie, les consommables n\'en créent pas (RG-15)', function () {
    $acquisition = draftAcquisition();

    $assets = app(ValidateAcquisition::class)($acquisition);

    expect($assets)->toHaveCount(6)
        ->and(Asset::query()->count())->toBe(6)
        ->and($assets->where('designation', 'Ordinateur portable HP ProBook 450'))->toHaveCount(3)
        ->and($assets->where('designation', 'Onduleur APC 1500 VA'))->toHaveCount(3)
        ->and(Asset::query()->where('designation', 'like', 'Câbles%')->exists())->toBeFalse()
        ->and($acquisition->status)->toBe(AcquisitionStatus::Validated);
});

it('attribue des numéros consécutifs', function () {
    $assets = app(ValidateAcquisition::class)(draftAcquisition());

    $numbers = $assets->pluck('number')->map(fn (string $number): int => (int) substr($number, -6))->all();

    expect($numbers)->toBe(range($numbers[0], $numbers[0] + 5));
});

it('reporte sur chaque fiche les données de l\'acquisition', function () {
    $acquisition = draftAcquisition();
    $asset = app(ValidateAcquisition::class)($acquisition)->first();
    $line = $acquisition->lines->first();

    expect($asset->acquisition_line_id)->toBe($line->id)
        ->and($asset->supplier_id)->toBe($this->supplier->id)
        ->and($asset->invoice_reference)->toBe('FAC-2026-1003')
        ->and($asset->funding_source->value)->toBe('grant')
        ->and($asset->site_id)->toBe($this->site->id)
        ->and($asset->department_id)->toBe($this->department->id)
        ->and($asset->acquired_on->toDateString())->toBe('2026-10-26')
        ->and($asset->acquisition_cost)->toBe(600000)
        ->and($asset->asset_condition_id)->toBe($this->condition->id)
        ->and($asset->status)->toBe(AssetStatus::InService)
        ->and($acquisition->assets()->count())->toBe(6);
});

it('applique les paramètres d\'amortissement de la catégorie (RG-30, RG-31)', function () {
    $assets = app(ValidateAcquisition::class)(draftAcquisition());

    $computer = $assets->firstWhere('asset_category_id', $this->computers->id);
    $inverter = $assets->firstWhere('asset_category_id', $this->electric->id);

    expect($computer->is_depreciable)->toBeTrue()
        ->and($computer->duration_years)->toBe(3)
        ->and($computer->asset_account)->toBe('2441')
        ->and($computer->depreciation_account)->toBe('28441')
        ->and($inverter->duration_years)->toBe(8);
});

it('ne rend pas amortissable une unité sous le seuil d\'immobilisation (RG-30)', function () {
    $threshold = AssetCategory::factory()->create(['depreciable' => Depreciability::ThresholdBased, 'default_duration_years' => 3]);
    $assets = app(ValidateAcquisition::class)(draftAcquisition([
        ['designation' => 'Clavier', 'asset_category_id' => $threshold->id, 'quantity' => 2, 'unit_price' => 20000, 'tracked_individually' => true],
    ]));

    expect($assets->every(fn (Asset $asset): bool => ! $asset->is_depreciable))->toBeTrue();
});

it('valide une acquisition de consommables sans créer de fiche', function () {
    $acquisition = draftAcquisition([
        ['designation' => 'Câbles', 'asset_category_id' => $this->misc->id, 'quantity' => 6, 'unit_price' => 7500, 'tracked_individually' => false],
    ]);

    expect(app(ValidateAcquisition::class)($acquisition))->toHaveCount(0)
        ->and($acquisition->status)->toBe(AcquisitionStatus::Validated)
        ->and(Asset::query()->count())->toBe(0);
});

it('ne crée aucune fiche si la validation échoue (transaction unique)', function () {
    $acquisition = draftAcquisition();
    $this->electric->update(['is_active' => false]);

    expect(fn () => app(ValidateAcquisition::class)($acquisition))->toThrow(DomainException::class);

    expect(Asset::query()->count())->toBe(0)
        ->and($acquisition->fresh()->status)->toBe(AcquisitionStatus::Draft);
});

it('annule les fiches déjà créées quand une création échoue en cours de route', function () {
    $acquisition = draftAcquisition();
    $created = 0;
    Asset::creating(function () use (&$created): void {
        if (++$created === 4) {
            throw new RuntimeException('Panne simulée');
        }
    });

    expect(fn () => app(ValidateAcquisition::class)($acquisition))->toThrow(RuntimeException::class);

    expect(Asset::query()->count())->toBe(0)
        ->and($acquisition->fresh()->status)->toBe(AcquisitionStatus::Draft);
});

it('refuse de valider deux fois', function () {
    $acquisition = draftAcquisition();
    app(ValidateAcquisition::class)($acquisition);

    expect(fn () => app(ValidateAcquisition::class)($acquisition->fresh()))->toThrow(DomainException::class)
        ->and(Asset::query()->count())->toBe(6);
});

it('verrouille une acquisition validée : plus de modification ni de suppression (RG-15)', function () {
    $acquisition = draftAcquisition();
    app(ValidateAcquisition::class)($acquisition);
    $acquisition = $acquisition->fresh();

    expect(fn () => app(UpdateAcquisition::class)($acquisition, [
        'acquired_on' => '2026-10-26', 'supplier_id' => $this->supplier->id, 'site_id' => $this->site->id, 'department_id' => $this->department->id,
        'lines' => [['designation' => 'X', 'asset_category_id' => $this->misc->id, 'quantity' => 1, 'unit_price' => 10, 'tracked_individually' => false]],
    ]))->toThrow(DomainException::class)
        ->and(fn () => app(DeleteAcquisition::class)($acquisition))->toThrow(DomainException::class)
        ->and(fn () => $acquisition->update(['invoice_reference' => 'AUTRE']))->toThrow(LogicException::class)
        ->and(fn () => $acquisition->delete())->toThrow(LogicException::class)
        ->and($this->admin->can('update', $acquisition))->toBeFalse()
        ->and($this->admin->can('delete', $acquisition))->toBeFalse()
        ->and($this->admin->can('validate', $acquisition))->toBeFalse();

    $this->get("/admin/acquisitions/{$acquisition->id}/edit")->assertForbidden();
});

it('refuse d\'ajouter un justificatif à une acquisition validée', function () {
    $acquisition = draftAcquisition();
    app(ValidateAcquisition::class)($acquisition);

    expect(fn () => app(AttachAcquisitionDocuments::class)(
        [UploadedFile::fake()->create('f.pdf', 10, 'application/pdf')],
        DocumentType::factory()->create(),
        $acquisition->fresh(),
    ))->toThrow(DomainException::class);
});

it('redirige vers l\'impression des étiquettes des biens créés (RG-16)', function () {
    $acquisition = draftAcquisition();

    $component = livewire(ViewAcquisition::class, ['record' => $acquisition->getRouteKey()])
        ->callAction('validate');

    $ids = Asset::query()->orderBy('id')->pluck('id')->all();

    expect($ids)->toHaveCount(6);
    $component->assertRedirect(url('/admin/etiquettes').'?biens='.implode(',', $ids));
    expect(LabelsUrl::for($ids))->toBe(url('/admin/etiquettes').'?biens='.implode(',', $ids));
});

it('redirige vers la fiche de l\'acquisition quand elle ne crée aucune fiche', function () {
    $acquisition = draftAcquisition([
        ['designation' => 'Câbles', 'asset_category_id' => $this->misc->id, 'quantity' => 6, 'unit_price' => 7500, 'tracked_individually' => false],
    ]);

    livewire(ViewAcquisition::class, ['record' => $acquisition->getRouteKey()])
        ->callAction('validate')
        ->assertRedirect(route('filament.admin.resources.acquisitions.view', $acquisition));
});

it('valide aussi depuis la liste (action de ligne)', function () {
    $acquisition = draftAcquisition();

    livewire(ListAcquisitions::class)
        ->callAction(TestAction::make('validate')->table($acquisition))
        ->assertRedirect();

    expect($acquisition->fresh()->status)->toBe(AcquisitionStatus::Validated);
});

it('rattache aussi les justificatifs aux biens créés quand la case est cochée', function () {
    $acquisition = draftAcquisition();
    $type = DocumentType::factory()->create();
    app(AttachAcquisitionDocuments::class)([UploadedFile::fake()->create('facture.pdf', 10, 'application/pdf')], $type, $acquisition);

    livewire(ViewAcquisition::class, ['record' => $acquisition->getRouteKey()])
        ->callAction('validate', ['attach_to_assets' => true]);

    $document = Document::query()->firstOrFail();

    expect($document->assets)->toHaveCount(6)
        ->and($document->acquisitions)->toHaveCount(1);
});

it('laisse les justificatifs sur l\'acquisition seule quand la case est décochée', function () {
    $acquisition = draftAcquisition();
    app(AttachAcquisitionDocuments::class)([UploadedFile::fake()->create('facture.pdf', 10, 'application/pdf')], DocumentType::factory()->create(), $acquisition);

    livewire(ViewAcquisition::class, ['record' => $acquisition->getRouteKey()])
        ->callAction('validate', ['attach_to_assets' => false]);

    expect(Document::query()->firstOrFail()->assets)->toHaveCount(0);
});

it('trace la création et la validation avec les numéros créés (RG-70)', function () {
    $acquisition = draftAcquisition();
    $assets = app(ValidateAcquisition::class)($acquisition);

    $created = Activity::query()->where('log_name', 'acquisitions')->where('event', 'created')->first();
    $validated = Activity::query()->where('log_name', 'acquisitions')->where('event', 'validated')->first();

    expect($created?->causer_id)->toBe($this->admin->id)
        ->and($created?->subject_id)->toBe($acquisition->id)
        ->and($validated?->causer_id)->toBe($this->admin->id)
        ->and($validated?->properties->get('numbers'))->toBe($assets->pluck('number')->all())
        ->and($validated?->properties->get('assets_count'))->toBe(6)
        ->and(Activity::query()->where('log_name', 'acquisitions')->where('event', 'updated')->count())->toBe(0);
});

it('rend visible un justificatif d\'acquisition dans la liste des documents', function () {
    $acquisition = draftAcquisition();
    app(AttachAcquisitionDocuments::class)([UploadedFile::fake()->create('facture.pdf', 10, 'application/pdf')], DocumentType::factory()->create(), $acquisition);

    expect(Document::query()->visible()->count())->toBe(1);
});

it('affiche la fiche d\'une acquisition validée avec ses fiches créées', function () {
    $acquisition = draftAcquisition();
    $assets = app(ValidateAcquisition::class)($acquisition);

    livewire(ViewAcquisition::class, ['record' => $acquisition->getRouteKey()])
        ->assertSee($acquisition->reference)
        ->assertSee($assets->first()->number)
        ->assertSee('Imprimer les étiquettes')
        ->assertActionHidden('validate');
});

it('recharge les lignes dans le formulaire de modification d\'un brouillon', function () {
    $acquisition = draftAcquisition();

    livewire(EditAcquisition::class, ['record' => $acquisition->getRouteKey()])
        ->assertFormSet(['invoice_reference' => 'FAC-2026-1003'])
        ->assertCount('data.lines', 3);
});
