<?php

namespace App\Filament\Resources\Assets\Schemas;

use App\Enums\AssetStatus;
use App\Enums\FundingSource;
use App\Filament\Resources\Assets\AssetResource;
use App\Models\Asset;
use App\Models\AssetCategory;
use App\Models\AssetCondition;
use App\Services\AssetNumberGenerator;
use App\Services\DepreciabilityResolver;
use App\Services\SerialNumberGuard;
use App\Support\Money;
use Closure;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Builder;

/**
 * E07 : création et modification d'une fiche (RG-10 à RG-14, RG-30, RG-31).
 */
class AssetForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(3)
            ->components([
                Grid::make(1)
                    ->columnSpan(2)
                    ->schema([
                        self::identification(),
                        self::acquisition(),
                        self::assignment(),
                        self::situation(),
                        self::depreciation(),
                    ]),
                Grid::make(1)
                    ->columnSpan(1)
                    ->schema([
                        Section::make('Numéro patrimonial')
                            ->schema([
                                Text::make(fn (?Asset $record): string => $record->number ?? 'Attribué à l\'enregistrement')
                                    ->extraAttributes(['class' => 'pat-mono text-xl font-extrabold text-primary-600']),
                                Text::make(fn (?Asset $record, Get $get): string => $record
                                    ? 'Il ne changera jamais, même en cas de transfert, et ne sera jamais réutilisé.'
                                    : self::numberPreview($get('asset_category_id')))
                                    ->color('gray'),
                            ]),
                        Section::make('Photo')
                            ->schema([
                                FileUpload::make('photo_path')
                                    ->hiddenLabel()
                                    ->image()
                                    ->disk('local')
                                    ->directory('biens/photos')
                                    ->visibility('private')
                                    ->maxSize(5120)
                                    ->helperText('Facultative. Image de 5 Mo au plus.'),
                            ]),
                    ]),
            ]);
    }

    private static function identification(): Section
    {
        return Section::make('Identification')
            ->columns(2)
            ->schema([
                TextInput::make('designation')
                    ->label('Désignation')
                    ->required()
                    ->maxLength(255),
                Select::make('asset_category_id')
                    ->label('Catégorie')
                    ->relationship('category', 'name', fn (Builder $query) => $query->where('is_active', true)->orderBy('name'))
                    ->helperText('Définit la règle d\'amortissement par défaut (et le préfixe si le numéro est « par catégorie »).')
                    ->required()
                    ->preload()
                    ->live(),
                TextInput::make('brand')->label('Marque')->maxLength(255),
                TextInput::make('model')->label('Modèle ou référence')->maxLength(255),
                TextInput::make('serial_number')
                    ->label('Numéro de série')
                    ->maxLength(255)
                    ->extraInputAttributes(['class' => 'pat-mono'])
                    ->live(onBlur: true)
                    ->rule(fn (?Asset $record): Closure => self::uniqueActiveSerial($record))
                    // RG-13 : lien vers le bien qui porte déjà ce numéro de série.
                    ->hintAction(fn (?Asset $record, Get $get): ?Action => ($existing = app(SerialNumberGuard::class)->conflictFor($get('serial_number'), $record?->id))
                        ? Action::make('openExistingAsset')
                            ->label('Voir le bien '.$existing->number)
                            ->url(AssetResource::getUrl('view', ['record' => $existing]), shouldOpenInNewTab: true)
                        : null),
                Textarea::make('description')->label('Description')->rows(3)->columnSpanFull(),
            ]);
    }

    private static function acquisition(): Section
    {
        return Section::make('Acquisition')
            ->columns(2)
            ->schema([
                DatePicker::make('acquired_on')
                    ->label('Date d\'acquisition')
                    ->required()
                    ->maxDate(now()),
                DatePicker::make('in_service_on')
                    ->label('Date de mise en service')
                    ->afterOrEqual('acquired_on'),
                Select::make('supplier_id')
                    ->label('Fournisseur')
                    ->relationship('supplier', 'name', fn (Builder $query) => $query->where('is_active', true)->orderBy('name'))
                    ->searchable()
                    ->preload(),
                TextInput::make('invoice_reference')->label('Référence de facture')->maxLength(255),
                TextInput::make('acquisition_cost')
                    ->label('Prix d\'acquisition (FCFA)')
                    ->integer()
                    ->minValue(0)
                    ->maxValue(999_999_999_999)
                    ->extraInputAttributes(['class' => 'pat-mono'])
                    ->required(fn (Get $get): bool => self::costRequired($get))
                    ->live(onBlur: true),
                Select::make('funding_source')
                    ->label('Mode de financement')
                    ->options(FundingSource::class),
            ]);
    }

    private static function assignment(): Section
    {
        return Section::make('Affectation')
            ->columns(2)
            ->schema([
                Select::make('site_id')
                    ->label('Site')
                    ->relationship('site', 'name', fn (Builder $query) => $query->where('is_active', true)->orderBy('name'))
                    ->required()
                    ->preload()
                    ->live()
                    ->afterStateUpdated(fn (Set $set) => $set('room_id', null)),
                Select::make('department_id')
                    ->label('Département')
                    ->relationship('department', 'name', fn (Builder $query) => $query->where('is_active', true)->orderBy('name'))
                    ->required()
                    ->preload()
                    ->live()
                    ->afterStateUpdated(fn (Set $set) => $set('service_id', null)),
                Select::make('service_id')
                    ->label('Service')
                    ->placeholder('Aucun')
                    ->relationship('service', 'name', fn (Builder $query, Get $get) => $query
                        ->where('is_active', true)
                        ->where('department_id', $get('department_id'))
                        ->orderBy('name')),
                Select::make('room_id')
                    ->label('Local')
                    ->placeholder('Aucun')
                    ->relationship('room', 'name', fn (Builder $query, Get $get) => $query
                        ->where('is_active', true)
                        ->where('site_id', $get('site_id'))
                        ->orderBy('name')),
                Select::make('holder_id')
                    ->label('Responsable / affectataire')
                    ->placeholder('Aucun')
                    ->relationship('holder', 'name', fn (Builder $query) => $query->where('is_active', true)->orderBy('name'))
                    ->searchable()
                    ->preload()
                    ->createOptionForm([
                        TextInput::make('name')->label('Nom')->required()->maxLength(255),
                        TextInput::make('phone')->label('Téléphone')->tel()->maxLength(30),
                    ])
                    ->createOptionModalHeading('Nouveau responsable')
                    ->helperText('Une personne, avec ou sans compte dans l\'application.')
                    ->columnSpanFull(),
            ]);
    }

    private static function situation(): Section
    {
        return Section::make('État et statut')
            ->columns(2)
            ->schema([
                Select::make('asset_condition_id')
                    ->label('État')
                    ->options(fn (): array => AssetCondition::query()->active()->orderBy('sort')->pluck('label', 'id')->all())
                    ->helperText('Condition physique ou fonctionnelle.')
                    ->required(),
                // RG-11, RG-12 : le statut est distinct de l'état ; certains ne sont posés que par une opération.
                Select::make('status')
                    ->label('Statut')
                    ->options(fn (?Asset $record): array => collect(AssetStatus::cases())
                        ->filter(fn (AssetStatus $status): bool => $status->isManuallySelectable() || $status === $record?->status)
                        ->mapWithKeys(fn (AssetStatus $status): array => [$status->value => $status->getLabel()])
                        ->all())
                    ->default(AssetStatus::InService->value)
                    ->disabled(fn (?Asset $record): bool => $record !== null && ! $record->status->isManuallySelectable())
                    ->helperText('Situation administrative. Emprunté, en maintenance, cédé et réformé sont posés par les opérations.')
                    ->required(),
                Textarea::make('notes')->label('Observations')->rows(2)->columnSpanFull(),
            ]);
    }

    private static function depreciation(): Section
    {
        $isDepreciable = fn (Get $get): bool => self::depreciable($get);

        return Section::make('Immobilisation')
            ->columns(2)
            ->schema([
                Toggle::make('depreciation_forced_off')
                    ->label('Forcer « non amortissable »')
                    ->helperText('RG-30 : à cocher pour suivre ce bien sans plan d\'amortissement.')
                    ->live()
                    ->columnSpanFull(),
                Text::make(fn (Get $get): string => self::depreciationSummary($get))
                    ->columnSpanFull(),
                TextInput::make('duration_years')
                    ->label('Durée (années)')
                    ->helperText('Vide = durée par défaut de la catégorie.')
                    ->integer()
                    ->minValue(1)
                    ->maxValue(100)
                    ->visible($isDepreciable),
                TextInput::make('residual_value')
                    ->label('Valeur résiduelle (FCFA)')
                    ->integer()
                    ->minValue(0)
                    ->default(0)
                    ->extraInputAttributes(['class' => 'pat-mono'])
                    ->visible($isDepreciable),
                TextInput::make('asset_account')
                    ->label('Compte d\'immobilisation')
                    ->helperText('Vide = compte de la catégorie.')
                    ->maxLength(20)
                    ->visible($isDepreciable),
                TextInput::make('depreciation_account')
                    ->label('Compte d\'amortissement')
                    ->maxLength(20)
                    ->visible($isDepreciable),
            ]);
    }

    private static function category(Get $get): ?AssetCategory
    {
        $id = $get('asset_category_id');

        return $id ? AssetCategory::query()->find($id) : null;
    }

    private static function costRequired(Get $get): bool
    {
        $category = self::category($get);

        return $category !== null && app(DepreciabilityResolver::class)->requiresCost($category, (bool) $get('depreciation_forced_off'));
    }

    private static function depreciable(Get $get): bool
    {
        $category = self::category($get);
        $cost = $get('acquisition_cost');

        return $category !== null && app(DepreciabilityResolver::class)->isDepreciable(
            $category,
            is_numeric($cost) ? (int) $cost : null,
            (bool) $get('depreciation_forced_off'),
        );
    }

    private static function depreciationSummary(Get $get): string
    {
        $category = self::category($get);

        if ($category === null) {
            return 'Choisissez une catégorie pour connaître la règle d\'amortissement.';
        }

        if (! self::depreciable($get)) {
            return 'Ce bien est suivi en quantité et en valeur, sans plan d\'amortissement.';
        }

        $cost = (int) $get('acquisition_cost');
        $duration = (int) ($get('duration_years') ?: $category->default_duration_years);

        return sprintf(
            'Bien amortissable · méthode linéaire sur %d ans · base %s FCFA · dotation annuelle %s FCFA. Le plan détaillé est calculé automatiquement.',
            $duration,
            Money::format($cost - (int) $get('residual_value')),
            Money::format($duration > 0 ? (int) round(($cost - (int) $get('residual_value')) / $duration) : null),
        );
    }

    private static function numberPreview(mixed $categoryId): string
    {
        $category = $categoryId ? AssetCategory::query()->find($categoryId) : null;

        return $category
            ? 'Prochain numéro prévu : '.app(AssetNumberGenerator::class)->preview($category).'. Il est attribué définitivement à l\'enregistrement.'
            : 'Il est attribué automatiquement à l\'enregistrement, ne change jamais et n\'est jamais réutilisé.';
    }

    /**
     * RG-13 : contrôle immédiat dans le formulaire ; la règle elle-même vit dans ApplyAssetRules.
     */
    private static function uniqueActiveSerial(?Asset $record): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) use ($record): void {
            $existing = app(SerialNumberGuard::class)->conflictFor($value, $record?->id);

            if ($existing !== null) {
                $fail(SerialNumberGuard::message($existing));
            }
        };
    }
}
