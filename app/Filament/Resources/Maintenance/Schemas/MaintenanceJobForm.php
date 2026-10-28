<?php

namespace App\Filament\Resources\Maintenance\Schemas;

use App\Enums\MaintenanceNature;
use App\Enums\ProviderType;
use App\Filament\Support\OperationFields;
use App\Models\Asset;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Illuminate\Support\Carbon;

/**
 * E23 : ouverture et modification d'une intervention. Aucune règle ici : tout passe par App\Actions\Maintenance.
 */
class MaintenanceJobForm
{
    public static function configure(Schema $schema): Schema
    {
        // Fiche d'un bien ou retour de prêt dégradé : le bien est présélectionné.
        $presetAsset = fn (): ?Asset => request()->integer('bien') > 0 ? Asset::query()->find(request()->integer('bien')) : null;

        return $schema->components([
            Section::make('Bien concerné')
                ->schema([
                    OperationFields::assetSelect('asset_id', 'Bien', statuses: null)
                        ->default(fn (): ?int => $presetAsset()?->getKey())
                        ->live()
                        ->afterStateUpdated(fn (mixed $state, Set $set) => $set('asset_version', filled($state) ? Asset::query()->whereKey($state)->value('lock_version') : null))
                        ->disabled(fn (string $operation): bool => $operation === 'edit')
                        ->dehydrated(fn (string $operation): bool => $operation === 'create')
                        ->required(),
                    // RG-86 : version de la fiche lue à la sélection du bien.
                    Hidden::make('asset_version')
                        ->default(fn (): mixed => $presetAsset()?->lock_version)
                        ->dehydrated(fn (string $operation): bool => $operation === 'create'),
                    // RG-64 : intervention créée depuis un signalement.
                    Hidden::make('report_id')
                        ->default(fn (): ?int => request()->integer('signalement') > 0 ? request()->integer('signalement') : null)
                        ->dehydrated(fn (string $operation): bool => $operation === 'create'),
                ]),
            Section::make('Intervention')
                ->columns(2)
                ->schema([
                    Select::make('nature')
                        ->label('Nature de l\'intervention')
                        ->options(MaintenanceNature::class)
                        ->native(false)
                        ->required(),
                    TextInput::make('reason')
                        ->label('Motif')
                        ->maxLength(255)
                        ->required(),
                    DatePicker::make('starts_on')
                        ->label('Date de début')
                        ->default(today())
                        ->live()
                        ->required()
                        ->helperText('Une date à venir crée une intervention planifiée.'),
                    Select::make('provider_type')
                        ->label('Intervenant')
                        ->options(ProviderType::class)
                        ->default(ProviderType::Provider->value)
                        ->native(false)
                        ->live()
                        ->required(),
                    TextInput::make('provider_name')
                        ->label(fn (Get $get): string => OperationFields::value($get('provider_type')) === ProviderType::Internal->value ? 'Service interne' : 'Prestataire')
                        ->maxLength(255),
                    TextInput::make('cost')
                        ->label('Coût')
                        ->numeric()
                        ->minValue(0)
                        ->suffix('FCFA')
                        ->helperText('Montant du devis, à confirmer par la facture. Il n\'entre pas dans la valeur amortissable du bien.'),
                    TextInput::make('cost_reference')
                        ->label('Référence devis ou facture')
                        ->maxLength(255),
                    Textarea::make('notes')
                        ->label('Observations')
                        ->rows(3)
                        ->columnSpanFull(),
                    Checkbox::make('put_in_maintenance')
                        ->label('Passer le bien au statut « En maintenance » pendant l\'intervention')
                        ->default(true)
                        ->visible(fn (Get $get, string $operation): bool => $operation === 'create' && filled($get('starts_on')) && ! Carbon::parse($get('starts_on'))->isAfter(today()))
                        ->columnSpanFull(),
                    ...array_map(
                        fn ($field) => $field->visible(fn (Get $get, string $operation): bool => $operation === 'create' && ($field->getName() === 'files' || filled($get('files')))),
                        OperationFields::documents('Joindre un devis ou un justificatif (facultatif)', 'Justificatif de maintenance'),
                    ),
                ]),
        ]);
    }
}
