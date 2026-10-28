<?php

namespace App\Filament\Pages\Depreciation;

use App\Actions\Depreciation\UpdateCategoryDepreciationRule;
use App\Actions\Depreciation\UpdateDepreciationSettings;
use App\Enums\DepreciationMethod;
use App\Models\Asset;
use App\Models\AssetCategory;
use App\Models\DepreciationSchedule;
use App\Services\DepreciationCalculator;
use App\Services\DepreciationPlanReport;
use App\Services\DepreciationRules;
use App\Services\Settings;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Concerns\InteractsWithSchemas;
use Filament\Schemas\Contracts\HasSchemas;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\SelectColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\TextInputColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Validation\ValidationException;
use UnitEnum;

/**
 * E32 : règles d'amortissement. Paramètres généraux (seuil, dégressif et coefficients ; prorata,
 * point de départ et arrondi fixés tant que Q1 n'est pas tranchée) et règles par catégorie.
 * RG-83 : modification réservée à l'administrateur et au comptable. Ne recalcule aucun bien existant.
 *
 * @property-read Schema $form
 */
class DepreciationRulesPage extends Page implements HasSchemas, HasTable
{
    use InteractsWithSchemas;
    use InteractsWithTable;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedScale;

    protected static string|UnitEnum|null $navigationGroup = 'Immobilisations';

    protected static ?int $navigationSort = 2;

    protected static ?string $navigationLabel = 'Règles d\'amortissement';

    protected static ?string $title = 'Règles d\'amortissement';

    protected static ?string $slug = 'regles-amortissement';

    protected string $view = 'filament.pages.depreciation-rules';

    /** @var array<string, mixed> */
    public array $data = [];

    public static function canAccess(): bool
    {
        return auth()->user()?->can('viewAny', DepreciationSchedule::class) ?? false;
    }

    public function getSubheading(): string
    {
        return 'Valeurs proposées par défaut à la création d\'un bien. Chaque fiche peut les ajuster, avec historisation.';
    }

    public function mount(): void
    {
        $settings = app(Settings::class);

        $this->form->fill([
            Settings::CAPITALIZATION_THRESHOLD => $settings->int(Settings::CAPITALIZATION_THRESHOLD),
            Settings::DECLINING_ENABLED => $settings->bool(Settings::DECLINING_ENABLED),
            Settings::DECLINING_COEFFICIENT_3_4 => str_replace('.', ',', $settings->string(Settings::DECLINING_COEFFICIENT_3_4)),
            Settings::DECLINING_COEFFICIENT_5_6 => str_replace('.', ',', $settings->string(Settings::DECLINING_COEFFICIENT_5_6)),
            Settings::DECLINING_COEFFICIENT_OVER_6 => str_replace('.', ',', $settings->string(Settings::DECLINING_COEFFICIENT_OVER_6)),
        ]);
    }

    public function canManage(): bool
    {
        return auth()->user()?->can('manageRules', DepreciationSchedule::class) ?? false;
    }

    public function form(Schema $schema): Schema
    {
        $readOnly = ! $this->canManage();

        return $schema
            ->statePath('data')
            ->disabled($readOnly)
            ->components([
                Section::make('Règles générales')
                    ->description('Durées, taux et règles de prorata à valider avec le responsable comptable de l\'Église au regard du référentiel SYSCOHADA (docs/06, Q1 à Q9).')
                    ->schema([
                        Grid::make(['default' => 1, 'md' => 3])->schema([
                            Text::make(fn (): string => 'Prorata : '.app(DepreciationRules::class)->prorataLabel().'. Seul mode défini tant que Q1 n\'est pas tranchée.'),
                            Text::make('Point de départ : date de mise en service (RG-32). Arrondi : au franc, demi-unité supérieure (RG-33).'),
                            Text::make('Valeur résiduelle : nulle par défaut, saisie par bien (Q6).'),
                        ]),
                        TextInput::make(Settings::CAPITALIZATION_THRESHOLD)
                            ->label('Seuil d\'immobilisation (FCFA)')
                            ->helperText('En dessous, le bien est suivi sans amortissement (RG-30, Q2).')
                            ->integer()
                            ->minValue(0)
                            ->required(),
                        Toggle::make(Settings::DECLINING_ENABLED)
                            ->label('Autoriser la méthode dégressive')
                            ->helperText('RG-35, Q4 : désactivée par défaut. Bascule en linéaire quand l\'annuité linéaire restante devient supérieure.')
                            ->live(),
                        Grid::make(['default' => 1, 'md' => 3])
                            ->visible(fn (Get $get): bool => (bool) $get(Settings::DECLINING_ENABLED))
                            ->schema([
                                TextInput::make(Settings::DECLINING_COEFFICIENT_3_4)->label('Coefficient 3 à 4 ans')->required(),
                                TextInput::make(Settings::DECLINING_COEFFICIENT_5_6)->label('Coefficient 5 à 6 ans')->required(),
                                TextInput::make(Settings::DECLINING_COEFFICIENT_OVER_6)->label('Coefficient plus de 6 ans')->required(),
                            ]),
                    ]),
            ]);
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('save')
                ->label('Enregistrer les règles')
                ->visible(fn (): bool => $this->canManage())
                ->action(function (Action $action): void {
                    abort_unless($this->canManage(), 403);

                    try {
                        app(UpdateDepreciationSettings::class)($this->form->getState());
                    } catch (ValidationException $exception) {
                        Notification::make()->title('Règles non enregistrées')->body(collect($exception->errors())->flatten()->implode(' '))->danger()->send();
                        $action->halt();
                    }

                    Notification::make()->title('Règles générales enregistrées')->body('Les biens déjà enregistrés ne sont pas recalculés.')->success()->send();
                }),
        ];
    }

    public function table(Table $table): Table
    {
        $locked = fn (): bool => ! $this->canManage();
        $update = fn (string $field) => function (AssetCategory $record, mixed $state) use ($field): mixed {
            abort_unless($this->canManage(), 403);

            try {
                app(UpdateCategoryDepreciationRule::class)($record, $field, $state);
            } catch (ValidationException $exception) {
                Notification::make()->title('Règle non enregistrée')->body(collect($exception->errors())->flatten()->implode(' '))->danger()->send();
            }

            return $state;
        };

        return $table
            ->query(AssetCategory::query()->where('is_active', true))
            ->defaultSort('name')
            ->heading('Règles par catégorie')
            ->description('Les comptes sont indicatifs et doivent être confirmés par le comptable (Q3, Q23). Une modification de règle ne recalcule pas les biens déjà enregistrés.')
            ->paginated(false)
            ->columns([
                TextColumn::make('name')->label('Catégorie')->weight('semibold'),
                TextColumn::make('depreciable')->label('Amortissable')->badge(),
                SelectColumn::make('default_method')
                    ->label('Méthode')
                    ->options(fn (): array => app(DepreciationRules::class)->decliningEnabled()
                        ? [DepreciationMethod::Linear->value => 'Linéaire', DepreciationMethod::Declining->value => 'Dégressive']
                        : [DepreciationMethod::Linear->value => 'Linéaire'])
                    ->selectablePlaceholder(false)
                    ->disabled($locked)
                    ->updateStateUsing($update('default_method')),
                TextInputColumn::make('default_duration_years')
                    ->label('Durée (ans)')
                    ->type('number')
                    ->rules(['required', 'integer', 'min:1', 'max:100'])
                    ->disabled($locked)
                    ->updateStateUsing($update('default_duration_years')),
                TextColumn::make('rate')
                    ->label('Taux')
                    ->fontFamily('mono')
                    ->state(fn (AssetCategory $record): string => $record->default_duration_years
                        ? DepreciationPlanReport::percent(DepreciationCalculator::formatScaled(DepreciationCalculator::roundHalfUp(1_000_000, $record->default_duration_years)))
                        : '·'),
                TextInputColumn::make('asset_account')->label('Compte immo.')->disabled($locked)->updateStateUsing($update('asset_account')),
                TextInputColumn::make('depreciation_account')->label('Compte amort.')->disabled($locked)->updateStateUsing($update('depreciation_account')),
                TextInputColumn::make('expense_account')->label('Compte dotation')->disabled($locked)->updateStateUsing($update('expense_account')),
                TextColumn::make('assets_count')
                    ->label('Biens')
                    ->state(fn (AssetCategory $record): int => Asset::query()->where('asset_category_id', $record->id)->count()),
            ]);
    }
}
