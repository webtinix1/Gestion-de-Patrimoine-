<?php

namespace App\Filament\Resources\AssetCategories\Schemas;

use App\Enums\Depreciability;
use App\Enums\DepreciationMethod;
use App\Filament\Support\Fields;
use App\Filament\Support\Referentials;
use App\Models\AssetCategory;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Illuminate\Support\Number;

class AssetCategoryForm
{
    public static function configure(Schema $schema): Schema
    {
        $isDepreciable = fn (Get $get): bool => self::depreciability($get('depreciable')) !== Depreciability::No;

        return $schema
            ->components([
                Section::make('Catégorie')
                    ->columns(2)
                    ->columnSpanFull()
                    ->schema([
                        TextInput::make('name')
                            ->label('Nom')
                            ->placeholder('Ex. Matériel médical')
                            ->required()
                            ->maxLength(255),
                        TextInput::make('prefix')
                            ->label('Préfixe du numéro')
                            ->placeholder('Ex. MED')
                            ->helperText('Trois lettres. Utilisé seulement si le numéro patrimonial est « par catégorie » (ex. INF-000001).')
                            ->required()
                            ->length(3)
                            ->regex('/^[A-Za-z]{3}$/')
                            ->validationMessages(['regex' => 'Le préfixe doit contenir exactement trois lettres sans accent.'])
                            ->afterStateUpdated(fn (?string $state, TextInput $component) => $component->state(Fields::upper($state)))
                            ->dehydrateStateUsing(fn (?string $state): ?string => Fields::upper($state))
                            ->extraInputAttributes(['class' => 'pat-mono', 'style' => 'text-transform: uppercase'])
                            ->unique(ignoreRecord: true),
                        Referentials::activeToggle('Catégorie active (proposée à la création des biens)'),
                    ]),
                Section::make('Règles d\'amortissement par défaut')
                    ->description('Appliquées aux nouveaux biens de la catégorie, modifiables sur chaque fiche (RG-31). Modifier ces valeurs ne recalcule pas les plans existants.')
                    ->columns(2)
                    ->columnSpanFull()
                    ->schema([
                        Select::make('depreciable')
                            ->label('Amortissable')
                            ->options(Depreciability::class)
                            ->default(Depreciability::Yes)
                            ->helperText('« Selon seuil » : amortissable si le prix d\'acquisition atteint le seuil d\'immobilisation (RG-30).')
                            ->required()
                            ->live(),
                        // RG-35 : le dégressif reste désactivé tant que le comptable ne l'a pas retenu.
                        Select::make('default_method')
                            ->label('Méthode par défaut')
                            ->options([DepreciationMethod::Linear->value => DepreciationMethod::Linear->getLabel()])
                            ->default(DepreciationMethod::Linear->value)
                            ->selectablePlaceholder(false)
                            ->required()
                            ->visible($isDepreciable),
                        TextInput::make('default_duration_years')
                            ->label('Durée par défaut (ans)')
                            ->integer()
                            ->minValue(1)
                            ->maxValue(100)
                            ->required($isDepreciable)
                            ->visible($isDepreciable)
                            ->live(onBlur: true),
                        Text::make(fn (Get $get): string => 'Taux linéaire calculé : '.self::formatRate($get('default_duration_years')))
                            ->visible($isDepreciable),
                        TextInput::make('asset_account')
                            ->label('Compte d\'immobilisation')
                            ->maxLength(20)
                            ->extraInputAttributes(['class' => 'pat-mono'])
                            ->visible($isDepreciable),
                        TextInput::make('depreciation_account')
                            ->label('Compte d\'amortissement')
                            ->maxLength(20)
                            ->extraInputAttributes(['class' => 'pat-mono'])
                            ->visible($isDepreciable),
                        TextInput::make('expense_account')
                            ->label('Compte de dotation')
                            ->maxLength(20)
                            ->extraInputAttributes(['class' => 'pat-mono'])
                            ->visible($isDepreciable),
                    ]),
            ]);
    }

    public static function formatRate(mixed $duration): string
    {
        $rate = AssetCategory::linearRate(is_numeric($duration) ? (int) $duration : null);

        return $rate === null ? '—' : Number::format($rate, 2, locale: 'fr').' %';
    }

    private static function depreciability(mixed $state): ?Depreciability
    {
        return $state instanceof Depreciability ? $state : Depreciability::tryFrom((string) $state);
    }
}
