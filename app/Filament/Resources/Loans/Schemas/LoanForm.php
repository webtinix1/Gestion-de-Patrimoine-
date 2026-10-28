<?php

namespace App\Filament\Resources\Loans\Schemas;

use App\Enums\AssetStatus;
use App\Enums\BorrowerType;
use App\Enums\LoanDirection;
use App\Filament\Support\OperationFields;
use App\Models\Asset;
use App\Models\Department;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;

/**
 * E16 : enregistrer un prêt par l'Église ou un emprunt auprès d'un tiers. Aucune règle ici : tout passe par
 * App\Actions\Loans\CreateLoan.
 */
class LoanForm
{
    public static function configure(Schema $schema): Schema
    {
        $isOut = fn (Get $get): bool => OperationFields::value($get('direction')) !== LoanDirection::In->value;
        $isIn = fn (Get $get): bool => OperationFields::value($get('direction')) === LoanDirection::In->value;
        // Fiche d'un bien ouverte depuis le bouton « Prêter » : le bien est présélectionné.
        $presetAsset = fn (): ?Asset => request()->integer('bien') > 0 ? Asset::query()->find(request()->integer('bien')) : null;

        return $schema->components([
            Radio::make('direction')
                ->label('Type d\'opération')
                ->options([
                    LoanDirection::Out->value => 'Prêt par l\'Église',
                    LoanDirection::In->value => 'Emprunt par l\'Église',
                ])
                ->descriptions([
                    LoanDirection::Out->value => 'Un bien du patrimoine sort temporairement.',
                    LoanDirection::In->value => 'Un bien d\'un tiers est reçu temporairement (hors patrimoine).',
                ])
                ->default(LoanDirection::Out->value)
                ->live()
                ->required(),

            Section::make('Matériel')
                ->columns(2)
                ->schema([
                    OperationFields::assetSelect('asset_ids', 'Biens à prêter', multiple: true, statuses: [AssetStatus::InService, AssetStatus::InStock])
                        ->helperText('Seuls les biens En service ou En stock sont disponibles. Scannez ou saisissez un numéro patrimonial.')
                        ->default(fn (): array => ($asset = $presetAsset()) !== null ? [$asset->getKey()] : [])
                        ->live()
                        ->afterStateUpdated(function (mixed $state, $livewire): void {
                            // RG-86 : la version de chaque fiche est retenue côté serveur à sa sélection, jamais rafraîchie ensuite.
                            $livewire->holdVersions(Asset::query()->whereKey((array) $state)->pluck('lock_version', 'id')->all());
                        })
                        ->visible($isOut)
                        ->required($isOut)
                        ->columnSpanFull(),
                    TextInput::make('external_designation')
                        ->label('Désignation du bien emprunté')
                        ->maxLength(255)
                        ->visible($isIn)
                        ->required($isIn),
                    TextInput::make('external_value')
                        ->label('Valeur estimée')
                        ->numeric()
                        ->minValue(0)
                        ->suffix('FCFA')
                        ->visible($isIn),
                    OperationFields::conditions('condition_out_id', 'État à la réception')
                        ->visible($isIn)
                        ->required($isIn),
                    Text::make('Un numéro de référence EXT- est attribué à l\'enregistrement. Le bien n\'entre ni dans le patrimoine ni dans les amortissements.')
                        ->color('gray')
                        ->visible($isIn)
                        ->columnSpanFull(),
                ]),

            Section::make(fn (Get $get): string => OperationFields::value($get('direction')) === LoanDirection::In->value ? 'Prêteur' : 'Bénéficiaire')
                ->columns(2)
                ->schema([
                    Select::make('borrower_type')
                        ->label('Type')
                        ->options(BorrowerType::class)
                        ->default(BorrowerType::External->value)
                        ->live()
                        ->native(false)
                        ->visible($isOut)
                        ->required($isOut),
                    Select::make('department_id')
                        ->label('Département')
                        ->options(fn (): array => Department::query()->where('is_active', true)->orderBy('name')->pluck('name', 'id')->all())
                        ->native(false)
                        ->visible(fn (Get $get): bool => $isOut($get) && OperationFields::value($get('borrower_type')) === BorrowerType::Internal->value)
                        ->required(fn (Get $get): bool => $isOut($get) && OperationFields::value($get('borrower_type')) === BorrowerType::Internal->value),
                    TextInput::make('borrower_name')
                        ->label(fn (Get $get): string => OperationFields::value($get('direction')) === LoanDirection::In->value ? 'Nom du prêteur' : 'Nom du bénéficiaire')
                        ->maxLength(255)
                        ->visible(fn (Get $get): bool => $isIn($get) || OperationFields::value($get('borrower_type')) !== BorrowerType::Internal->value)
                        ->required(fn (Get $get): bool => $isIn($get) || OperationFields::value($get('borrower_type')) !== BorrowerType::Internal->value),
                    TextInput::make('borrower_contact')
                        ->label('Contact (personne, téléphone)')
                        ->maxLength(255),
                ]),

            Section::make('Conditions')
                ->columns(2)
                ->schema([
                    DatePicker::make('out_on')
                        ->label(fn (Get $get): string => OperationFields::value($get('direction')) === LoanDirection::In->value ? 'Date de réception' : 'Date de sortie')
                        ->default(today())
                        ->maxDate(today())
                        ->live()
                        ->required(),
                    DatePicker::make('due_on')
                        ->label('Date de retour prévue')
                        ->minDate(fn (Get $get): mixed => $get('out_on'))
                        ->required(),
                    OperationFields::users('responsible_id', 'Responsable de l\'opération')
                        ->default(fn (): mixed => auth()->id())
                        ->required(),
                    Textarea::make('notes')
                        ->label('Observations')
                        ->rows(3)
                        ->columnSpanFull(),
                    ...array_map(
                        fn ($field) => $field->visible(fn (Get $get): bool => OperationFields::value($get('direction')) !== LoanDirection::In->value && ($field->getName() === 'files' || filled($get('files')))),
                        OperationFields::documents('Joindre la fiche de prêt signée (facultatif)', 'Document de prêt'),
                    ),
                ]),
        ]);
    }
}
