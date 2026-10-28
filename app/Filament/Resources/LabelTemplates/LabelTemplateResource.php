<?php

namespace App\Filament\Resources\LabelTemplates;

use App\Enums\LabelCodeType;
use App\Enums\LabelSheetLayout;
use App\Filament\Resources\LabelTemplates\Pages\CreateLabelTemplate;
use App\Filament\Resources\LabelTemplates\Pages\EditLabelTemplate;
use App\Filament\Resources\LabelTemplates\Pages\ListLabelTemplates;
use App\Models\LabelTemplate;
use App\Services\Labels\LabelComposer;
use App\Services\Labels\LabelSheetBuilder;
use App\Support\Labels\LabelContent;
use App\Support\Labels\SheetSlot;
use BackedEnum;
use Closure;
use DomainException;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Components\View;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use UnitEnum;

/**
 * E10 : modèles d'étiquettes, avec aperçu en direct. RG-95, RG-96.
 */
class LabelTemplateResource extends Resource
{
    protected static ?string $model = LabelTemplate::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedTag;

    protected static string|UnitEnum|null $navigationGroup = 'Patrimoine';

    protected static ?int $navigationSort = 4;

    protected static bool $hasTitleCaseModelLabel = false;

    protected static ?string $modelLabel = 'modèle d\'étiquette';

    protected static ?string $pluralModelLabel = 'Modèles d\'étiquettes';

    protected static ?string $navigationLabel = 'Modèles d\'étiquettes';

    protected static ?string $slug = 'modeles-etiquettes';

    protected static ?string $recordTitleAttribute = 'name';

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Grid::make(['default' => 1, 'xl' => 2])
                    ->columnSpanFull()
                    ->schema([
                        Section::make('Contenu')
                            ->schema([
                                TextInput::make('name')
                                    ->label('Nom du modèle')
                                    ->required()
                                    ->maxLength(100)
                                    ->unique(ignoreRecord: true),
                                TextInput::make('header_text')
                                    ->label('Texte d\'en-tête')
                                    ->helperText('Nom ou sigle de l\'Église. Laissé vide, le sigle des paramètres est imprimé (RG-95).')
                                    ->maxLength(60)
                                    ->live(onBlur: true),
                                Toggle::make('show_designation')->label('Désignation du matériel')->default(true)->live(),
                                Toggle::make('show_department')->label('Département ou service')->default(true)->live(),
                                Toggle::make('show_site')->label('Site de rattachement')->live(),
                                Toggle::make('show_room')->label('Local')->live(),
                                Select::make('code_type')
                                    ->label('Code')
                                    ->options(LabelCodeType::class)
                                    ->default(LabelCodeType::Qr)
                                    ->required()
                                    ->native(false)
                                    ->live()
                                    ->helperText('Le code ne contient que le numéro patrimonial : il reste valable après un transfert.'),
                                Select::make('sheet_layout')
                                    ->label('Support')
                                    ->options(LabelSheetLayout::class)
                                    ->default(LabelSheetLayout::A4ThreeByEight)
                                    ->required()
                                    ->native(false)
                                    ->live()
                                    ->afterStateUpdated(function (Set $set, mixed $state): void {
                                        $layout = $state instanceof LabelSheetLayout ? $state : LabelSheetLayout::tryFrom((string) $state);

                                        if ($layout !== null) {
                                            [$width, $height] = $layout->defaultSize();
                                            $set('width_mm', $width);
                                            $set('height_mm', $height);
                                        }
                                    }),
                                Grid::make(2)->schema([
                                    TextInput::make('width_mm')
                                        ->label('Largeur (mm)')
                                        ->numeric()
                                        ->required()
                                        ->default(70)
                                        ->minValue(20)
                                        ->maxValue(210)
                                        ->step(0.1)
                                        ->live(onBlur: true)
                                        ->rule(self::fitsOnSupport()),
                                    TextInput::make('height_mm')
                                        ->label('Hauteur (mm)')
                                        ->numeric()
                                        ->required()
                                        ->default(37)
                                        ->minValue(15)
                                        ->maxValue(297)
                                        ->step(0.1)
                                        ->live(onBlur: true)
                                        ->rule(self::fitsOnSupport()),
                                ]),
                                Toggle::make('is_default')
                                    ->label('Modèle par défaut de son support')
                                    ->helperText('Proposé en premier à l\'impression, pour les planches comme pour les rouleaux.'),
                            ]),
                        Section::make('Aperçu en direct')
                            ->description('Exemple avec le bien PAT-000125. L\'impression suit exactement cette mise en page.')
                            ->schema([
                                View::make('filament.labels.template-preview')
                                    ->viewData(fn (Get $get): array => self::previewData($get)),
                            ]),
                    ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('name')
            ->columns([
                TextColumn::make('name')
                    ->label('Modèle')
                    ->weight('semibold')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('size')
                    ->label('Format')
                    ->state(fn (LabelTemplate $record): string => $record->summary()),
                TextColumn::make('sheet_layout')
                    ->label('Support')
                    ->badge()
                    ->color('gray'),
                IconColumn::make('is_default')
                    ->label('Par défaut')
                    ->boolean(),
            ])
            ->recordActions([
                EditAction::make(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListLabelTemplates::route('/'),
            'create' => CreateLabelTemplate::route('/create'),
            'edit' => EditLabelTemplate::route('/{record}/edit'),
        ];
    }

    /**
     * RG-96 : colonnes × largeur ≤ 210 mm et lignes × hauteur ≤ 297 mm sur une planche A4.
     */
    private static function fitsOnSupport(): Closure
    {
        return fn (Get $get): Closure => function (string $attribute, mixed $value, Closure $fail) use ($get): void {
            $template = self::templateFromState($get);

            if (! app(LabelSheetBuilder::class)->fits($template)) {
                $fail('Avec ce support, les étiquettes dépassent la page A4 : réduisez la largeur ou la hauteur.');
            } else {
                try {
                    app(LabelComposer::class)->assertCanPrint($template);
                } catch (DomainException $exception) {
                    $fail($exception->getMessage());
                }
            }
        };
    }

    private static function templateFromState(Get $get): LabelTemplate
    {
        $layout = $get('sheet_layout');
        $code = $get('code_type');

        return new LabelTemplate([
            'name' => (string) $get('name'),
            'width_mm' => max(20.0, (float) ($get('width_mm') ?: 70)),
            'height_mm' => max(15.0, (float) ($get('height_mm') ?: 37)),
            'sheet_layout' => $layout instanceof LabelSheetLayout ? $layout : (LabelSheetLayout::tryFrom((string) $layout) ?? LabelSheetLayout::A4ThreeByEight),
            'code_type' => $code instanceof LabelCodeType ? $code : (LabelCodeType::tryFrom((string) $code) ?? LabelCodeType::Qr),
            'header_text' => $get('header_text'),
            'show_designation' => (bool) $get('show_designation'),
            'show_department' => (bool) $get('show_department'),
            'show_site' => (bool) $get('show_site'),
            'show_room' => (bool) $get('show_room'),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private static function previewData(Get $get): array
    {
        $template = self::templateFromState($get);
        try {
            $label = app(LabelComposer::class)->compose(
                $template,
                new LabelContent('PAT-000125', 'Ordinateur portable HP', 'Administration', 'Siège', 'Bureau administratif'),
                vector: true,
            );
        } catch (DomainException $exception) {
            return ['pages' => [], 'width' => $template->width_mm, 'height' => $template->height_mm, 'error' => $exception->getMessage()];
        }

        return [
            'pages' => [[new SheetSlot(1, 0.0, 0.0, $template->width_mm, $template->height_mm, $label)]],
            'width' => $template->width_mm,
            'height' => $template->height_mm,
            'error' => null,
        ];
    }
}
