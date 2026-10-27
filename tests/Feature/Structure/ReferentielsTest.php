<?php

use App\Enums\AssetStatus;
use App\Enums\Depreciability;
use App\Enums\DepreciationMethod;
use App\Filament\Resources\AssetCategories\Pages\CreateAssetCategory;
use App\Filament\Resources\AssetCategories\Pages\ListAssetCategories;
use App\Filament\Resources\AssetConditions\Pages\ManageAssetConditions;
use App\Filament\Resources\DocumentTypes\Pages\ManageDocumentTypes;
use App\Filament\Resources\Suppliers\Pages\ManageSuppliers;
use App\Models\AssetCategory;
use App\Models\AssetCondition;
use App\Models\DocumentType;
use App\Models\Supplier;
use App\Models\User;
use Filament\Actions\CreateAction;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;

use function Pest\Livewire\livewire;

beforeEach(function () {
    $this->actingAs(User::factory()->admin()->create());
    Filament::setCurrentPanel(Filament::getPanel('admin'));
});

describe('catégories de biens', function () {
    it('crée une catégorie avec ses règles par défaut', function () {
        livewire(CreateAssetCategory::class)
            ->fillForm([
                'name' => 'Matériel médical',
                'prefix' => 'med',
                'depreciable' => Depreciability::Yes->value,
                'default_duration_years' => 4,
                'asset_account' => '2448',
                'depreciation_account' => '28448',
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        expect(AssetCategory::query()->sole())
            ->prefix->toBe('MED')
            ->depreciable->toBe(Depreciability::Yes)
            ->default_method->toBe(DepreciationMethod::Linear)
            ->default_duration_years->toBe(4)
            ->asset_account->toBe('2448');
    });

    it('exige un préfixe unique de trois lettres', function (string $prefix, string $rule) {
        AssetCategory::factory()->create(['prefix' => 'INF']);

        livewire(CreateAssetCategory::class)
            ->fillForm(['name' => 'Test', 'prefix' => $prefix, 'depreciable' => 'no'])
            ->call('create')
            ->assertHasFormErrors(['prefix' => $rule]);
    })->with([
        'déjà utilisé' => ['INF', 'unique'],
        'trop court' => ['IN', 'size'],
        'chiffres' => ['IN1', 'regex'],
    ]);

    it('exige une durée pour une catégorie amortissable, pas pour une non amortissable', function () {
        livewire(CreateAssetCategory::class)
            ->fillForm(['name' => 'Matériel de culte', 'prefix' => 'CUL', 'depreciable' => 'threshold', 'default_duration_years' => null])
            ->call('create')
            ->assertHasFormErrors(['default_duration_years' => 'required']);

        livewire(CreateAssetCategory::class)
            ->fillForm(['name' => 'Autres équipements', 'prefix' => 'AUT', 'depreciable' => 'no'])
            ->call('create')
            ->assertHasNoFormErrors();
    });

    it('calcule le taux linéaire (RG-31)', function () {
        expect(AssetCategory::linearRate(3))->toBe(33.33)
            ->and(AssetCategory::linearRate(5))->toBe(20.0)
            ->and(AssetCategory::linearRate(null))->toBeNull();

        livewire(ListAssetCategories::class)->assertOk();
    });

    it('ne propose pas la méthode dégressive tant qu\'elle n\'est pas retenue (RG-35)', function () {
        livewire(CreateAssetCategory::class)
            ->assertFormFieldExists('default_method', function ($field): bool {
                return array_keys($field->getOptions()) === [DepreciationMethod::Linear->value];
            });
    });
});

describe('états', function () {
    it('ajoute un état en fin de liste', function () {
        AssetCondition::factory()->create(['sort' => 4]);

        livewire(ManageAssetConditions::class)
            ->callAction(CreateAction::class, ['label' => 'À vérifier', 'color' => 'info'])
            ->assertHasNoFormErrors();

        expect(AssetCondition::query()->where('label', 'À vérifier')->sole())
            ->sort->toBe(5)
            ->color->toBe('info');
    });

    it('réordonne les états', function () {
        $neuf = AssetCondition::factory()->create(['sort' => 1]);
        $bon = AssetCondition::factory()->create(['sort' => 2]);

        livewire(ManageAssetConditions::class)
            ->call('reorderTable', [(string) $bon->id, (string) $neuf->id]);

        expect($bon->refresh()->sort)->toBe(1)
            ->and($neuf->refresh()->sort)->toBe(2);
    });
});

describe('fournisseurs et types de documents', function () {
    it('crée un fournisseur', function () {
        livewire(ManageSuppliers::class)
            ->callAction(CreateAction::class, ['name' => 'Informatique Plus', 'phone' => '+242 06 000 00 00'])
            ->assertHasNoFormErrors();

        expect(Supplier::query()->sole()->name)->toBe('Informatique Plus');
    });

    it('crée un type de document et refuse un doublon', function () {
        livewire(ManageDocumentTypes::class)
            ->callAction(CreateAction::class, ['label' => 'Facture d\'achat'])
            ->assertHasNoFormErrors()
            ->callAction(CreateAction::class, ['label' => 'Facture d\'achat'])
            ->assertHasFormErrors(['label' => 'unique']);

        expect(DocumentType::query()->count())->toBe(1);
    });

    it('désactive un fournisseur', function () {
        $supplier = Supplier::factory()->create();

        livewire(ManageSuppliers::class)
            ->callAction(TestAction::make('deactivate')->table($supplier));

        expect($supplier->refresh()->is_active)->toBeFalse()
            ->and(Supplier::query()->active()->count())->toBe(0);
    });
});

describe('statuts', function () {
    it('réserve aux opérations les statuts En maintenance, Emprunté, Cédé et Réformé (RG-12)', function () {
        $manual = collect(AssetStatus::cases())->filter->isManuallySelectable()->map->getLabel()->values()->all();

        expect($manual)->toBe(['En service', 'En stock', 'Hors service']);
    });
});
