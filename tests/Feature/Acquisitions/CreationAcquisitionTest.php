<?php

use App\Actions\Acquisitions\CreateAcquisition;
use App\Actions\Acquisitions\DeleteAcquisition;
use App\Actions\Acquisitions\UpdateAcquisition;
use App\Enums\AcquisitionStatus;
use App\Filament\Resources\Acquisitions\Pages\CreateAcquisition as CreateAcquisitionPage;
use App\Models\Acquisition;
use App\Models\AssetCategory;
use App\Models\Department;
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

    $this->manager = User::factory()->role(DefaultRoles::MANAGER)->create();
    $this->actingAs($this->manager);

    $this->site = Site::factory()->create();
    $this->department = Department::factory()->create();
    $this->supplier = Supplier::factory()->create(['name' => 'Informatique Plus']);
    $this->computers = AssetCategory::factory()->create(['name' => 'Matériel informatique', 'prefix' => 'INF']);
    $this->misc = AssetCategory::factory()->create(['name' => 'Autres équipements', 'prefix' => 'AUT']);
});

function acquisitionData(array $overrides = []): array
{
    return [
        'acquired_on' => '2026-10-26',
        'supplier_id' => test()->supplier->id,
        'invoice_reference' => 'FAC-2026-1003',
        'funding_source' => 'own_funds',
        'site_id' => test()->site->id,
        'department_id' => test()->department->id,
        'lines' => [
            ['designation' => 'Ordinateur portable HP ProBook 450', 'asset_category_id' => test()->computers->id, 'quantity' => 3, 'unit_price' => 600000, 'tracked_individually' => true],
            ['designation' => 'Câbles HDMI 3 m', 'asset_category_id' => test()->misc->id, 'quantity' => 6, 'unit_price' => 7500, 'tracked_individually' => false],
        ],
        ...$overrides,
    ];
}

it('enregistre une acquisition multi-lignes en brouillon avec sa référence (RG-15)', function () {
    $acquisition = app(CreateAcquisition::class)(acquisitionData());

    expect($acquisition->reference)->toBe('ACQ-2026-001')
        ->and($acquisition->status)->toBe(AcquisitionStatus::Draft)
        ->and($acquisition->lines)->toHaveCount(2)
        ->and($acquisition->supplier_id)->toBe($this->supplier->id)
        ->and($acquisition->created_by)->toBe($this->manager->id)
        ->and($acquisition->assets()->count())->toBe(0);
});

it('calcule les totaux au lieu de les reprendre de la saisie', function () {
    $data = acquisitionData();
    $data['lines'][0]['line_total'] = 1;
    $data['total_amount'] = 5;

    $acquisition = app(CreateAcquisition::class)($data);

    expect($acquisition->lines->pluck('line_total')->all())->toBe([1_800_000, 45_000])
        ->and($acquisition->total_amount)->toBe(1_845_000);
});

it('attribue des références consécutives par année, sans doublon', function () {
    $first = app(CreateAcquisition::class)(acquisitionData());
    $second = app(CreateAcquisition::class)(acquisitionData());
    $previousYear = app(CreateAcquisition::class)(acquisitionData(['acquired_on' => '2025-12-30']));

    expect([$first->reference, $second->reference, $previousYear->reference])->toBe(['ACQ-2026-001', 'ACQ-2026-002', 'ACQ-2025-001']);
});

it('ne réutilise pas la référence d\'un brouillon supprimé', function () {
    $draft = app(CreateAcquisition::class)(acquisitionData());
    app(DeleteAcquisition::class)($draft);

    expect(app(CreateAcquisition::class)(acquisitionData())->reference)->toBe('ACQ-2026-002');
});

it('refuse une acquisition sans ligne, une quantité nulle ou une catégorie désactivée', function (array $overrides) {
    expect(fn () => app(CreateAcquisition::class)(acquisitionData($overrides)))->toThrow(ValidationException::class);
})->with([
    'sans ligne' => fn () => ['lines' => []],
    'quantité nulle' => fn () => ['lines' => [['designation' => 'X', 'asset_category_id' => test()->computers->id, 'quantity' => 0, 'unit_price' => 10, 'tracked_individually' => true]]],
    'prix négatif' => fn () => ['lines' => [['designation' => 'X', 'asset_category_id' => test()->computers->id, 'quantity' => 1, 'unit_price' => -5, 'tracked_individually' => true]]],
    'sans fournisseur' => fn () => ['supplier_id' => null],
    'date future' => fn () => ['acquired_on' => now()->addDay()->toDateString()],
]);

it('refuse une catégorie désactivée', function () {
    $inactive = AssetCategory::factory()->inactive()->create();

    expect(fn () => app(CreateAcquisition::class)(acquisitionData(['lines' => [
        ['designation' => 'X', 'asset_category_id' => $inactive->id, 'quantity' => 1, 'unit_price' => 10, 'tracked_individually' => true],
    ]])))->toThrow(ValidationException::class);
});

it('remplace les lignes et recalcule le total en modifiant un brouillon', function () {
    $acquisition = app(CreateAcquisition::class)(acquisitionData());

    app(UpdateAcquisition::class)($acquisition, acquisitionData(['lines' => [
        ['designation' => 'Onduleur', 'asset_category_id' => $this->computers->id, 'quantity' => 2, 'unit_price' => 145000, 'tracked_individually' => true],
    ]]));

    $acquisition->refresh();

    expect($acquisition->lines)->toHaveCount(1)
        ->and($acquisition->total_amount)->toBe(290_000)
        ->and($acquisition->reference)->toBe('ACQ-2026-001');
});

it('supprime un brouillon qui n\'a créé aucune fiche', function () {
    $acquisition = app(CreateAcquisition::class)(acquisitionData());

    app(DeleteAcquisition::class)($acquisition);

    expect(Acquisition::query()->count())->toBe(0)
        ->and(DB::table('acquisition_lines')->count())->toBe(0);
});

it('enregistre une acquisition avec ses lignes et un justificatif depuis le formulaire (E12)', function () {
    $type = DocumentType::factory()->create(['label' => 'Facture']);

    livewire(CreateAcquisitionPage::class)
        ->fillForm([
            'acquired_on' => '2026-10-26',
            'supplier_id' => $this->supplier->id,
            'invoice_reference' => 'FAC-2026-1003',
            'site_id' => $this->site->id,
            'department_id' => $this->department->id,
            'lines' => [
                ['designation' => 'Ordinateur portable', 'asset_category_id' => $this->computers->id, 'quantity' => 3, 'unit_price' => 600000, 'tracked_individually' => true],
                ['designation' => 'Câbles HDMI', 'asset_category_id' => $this->misc->id, 'quantity' => 6, 'unit_price' => 7500, 'tracked_individually' => false],
            ],
            'attachments' => [UploadedFile::fake()->create('FAC-2026-1003.pdf', 100, 'application/pdf')],
            'attachment_type_id' => $type->id,
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $acquisition = Acquisition::query()->firstOrFail();

    expect($acquisition->total_amount)->toBe(1_845_000)
        ->and($acquisition->lines)->toHaveCount(2)
        ->and($acquisition->documents)->toHaveCount(1)
        ->and($acquisition->documents->first()->original_name)->toBe('FAC-2026-1003.pdf');
});
