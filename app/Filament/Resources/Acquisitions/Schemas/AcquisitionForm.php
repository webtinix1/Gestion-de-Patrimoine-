<?php

namespace App\Filament\Resources\Acquisitions\Schemas;

use App\Enums\FundingSource;
use App\Models\AssetCategory;
use App\Models\Department;
use App\Models\DocumentType;
use App\Models\Site;
use App\Models\Supplier;
use App\Services\AcquisitionRules;
use App\Services\DocumentRules;
use App\Support\FileSize;
use App\Support\Money;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Repeater\TableColumn;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Builder;

/**
 * E12 : facture et affectation, lignes de la facture, justificatifs (RG-15, RG-65).
 * Les totaux affichés sont calculés, jamais saisis.
 */
class AcquisitionForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                self::header(),
                self::lines(),
                self::summary(),
                self::attachments(),
            ]);
    }

    private static function header(): Section
    {
        return Section::make('Facture et affectation')
            ->columns(3)
            ->schema([
                DatePicker::make('acquired_on')
                    ->label('Date d\'acquisition')
                    ->default(now())
                    ->maxDate(now())
                    ->required(),
                Select::make('supplier_id')
                    ->label('Fournisseur')
                    ->options(fn (): array => Supplier::query()->where('is_active', true)->orderBy('name')->pluck('name', 'id')->all())
                    ->searchable()
                    ->required(),
                TextInput::make('invoice_reference')
                    ->label('Référence de facture')
                    ->maxLength(255),
                Select::make('funding_source')
                    ->label('Mode de financement')
                    ->options(FundingSource::class),
                Select::make('site_id')
                    ->label('Site de rattachement')
                    ->options(fn (): array => self::perimeter(Site::query(), auth()->user()?->perimeterSiteIds())->pluck('name', 'id')->all())
                    ->required(),
                Select::make('department_id')
                    ->label('Département bénéficiaire')
                    ->options(fn (): array => self::perimeter(Department::query(), auth()->user()?->perimeterDepartmentIds())->pluck('name', 'id')->all())
                    ->required(),
            ]);
    }

    private static function lines(): Section
    {
        return Section::make('Lignes de la facture')
            ->schema([
                Repeater::make('lines')
                    ->hiddenLabel()
                    ->table([
                        TableColumn::make('Désignation')->markAsRequired(),
                        TableColumn::make('Catégorie')->markAsRequired(),
                        TableColumn::make('Qté')->width('90px')->markAsRequired(),
                        TableColumn::make('Prix unitaire (FCFA)')->width('160px')->markAsRequired(),
                        TableColumn::make('Total (FCFA)')->width('150px'),
                        TableColumn::make('Suivi individuel')->width('130px'),
                    ])
                    ->schema([
                        TextInput::make('designation')
                            ->required()
                            ->maxLength(255),
                        Select::make('asset_category_id')
                            ->options(fn (): array => AssetCategory::query()->where('is_active', true)->orderBy('name')->pluck('name', 'id')->all())
                            ->searchable()
                            ->required(),
                        TextInput::make('quantity')
                            ->integer()
                            ->minValue(1)
                            ->maxValue(AcquisitionRules::MAX_QUANTITY)
                            ->default(1)
                            ->required()
                            ->live(onBlur: true)
                            ->afterStateUpdated(fn (Get $get, Set $set) => self::refreshLineTotal($get, $set)),
                        TextInput::make('unit_price')
                            ->integer()
                            ->minValue(0)
                            ->maxValue(AcquisitionRules::MAX_PRICE)
                            ->extraInputAttributes(['class' => 'pat-mono'])
                            ->required()
                            ->live(onBlur: true)
                            ->afterStateUpdated(fn (Get $get, Set $set) => self::refreshLineTotal($get, $set)),
                        TextInput::make('line_total')
                            ->disabled()
                            ->dehydrated(false)
                            ->extraInputAttributes(['class' => 'pat-mono'])
                            ->formatStateUsing(fn (mixed $state, Get $get): string => Money::format(self::lineTotal($get('quantity'), $get('unit_price')))),
                        Toggle::make('tracked_individually')
                            ->default(true)
                            ->inline(false)
                            ->helperText('Une fiche par unité'),
                    ])
                    ->default([['quantity' => 1, 'tracked_individually' => true]])
                    ->minItems(1)
                    ->addActionLabel('Ajouter une ligne')
                    ->live()
                    ->reorderable(false),
            ]);
    }

    private static function summary(): Section
    {
        return Section::make('Montant et fiches')
            ->schema([
                Text::make(fn (Get $get): string => self::summaryText($get('lines')))
                    ->extraAttributes(['class' => 'font-semibold']),
                Text::make('Les numéros patrimoniaux sont attribués à la validation. Les numéros de série, locaux et responsables se complètent ensuite sur chaque fiche ; les règles d\'amortissement de la catégorie s\'appliquent par défaut.')
                    ->color('gray'),
            ]);
    }

    private static function attachments(): Section
    {
        $maxKb = app(DocumentRules::class)->maxSizeKb();

        return Section::make('Justificatifs')
            ->description('Facture, bon de livraison ou procès-verbal. Vous pourrez en ajouter ensuite depuis la fiche de l\'acquisition.')
            ->visible(fn (string $operation): bool => $operation === 'create')
            ->columns(2)
            ->schema([
                FileUpload::make('attachments')
                    ->label('Fichiers')
                    ->multiple()
                    ->storeFiles(false)
                    ->disk(DocumentRules::DISK)
                    ->acceptedFileTypes(DocumentRules::MIME_TYPES)
                    ->maxSize($maxKb)
                    ->helperText('PDF, images, Word ou Excel. '.FileSize::format($maxKb * 1024).' au maximum par fichier.')
                    ->columnSpanFull(),
                Select::make('attachment_type_id')
                    ->label('Type de document')
                    ->options(fn (): array => DocumentType::query()->active()->orderBy('label')->pluck('label', 'id')->all())
                    ->requiredWith('attachments')
                    ->native(false),
            ]);
    }

    private static function refreshLineTotal(Get $get, Set $set): void
    {
        $set('line_total', Money::format(self::lineTotal($get('quantity'), $get('unit_price'))));
    }

    private static function lineTotal(mixed $quantity, mixed $unitPrice): ?int
    {
        return is_numeric($quantity) && is_numeric($unitPrice) ? AcquisitionRules::lineTotal((int) $quantity, (int) $unitPrice) : null;
    }

    private static function summaryText(mixed $lines): string
    {
        $total = 0;
        $assets = 0;

        foreach (is_array($lines) ? $lines : [] as $line) {
            $total += self::lineTotal($line['quantity'] ?? null, $line['unit_price'] ?? null) ?? 0;

            if (! empty($line['tracked_individually']) && is_numeric($line['quantity'] ?? null)) {
                $assets += (int) $line['quantity'];
            }
        }

        return sprintf('Montant total : %s FCFA · %d fiche(s) seront créées à la validation.', Money::format($total), $assets);
    }

    /**
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  Builder<TModel>  $query
     * @param  list<int>|null  $allowedIds
     * @return Builder<TModel>
     */
    private static function perimeter($query, ?array $allowedIds)
    {
        return $query
            ->where('is_active', true)
            ->when($allowedIds !== null, fn ($query) => $query->whereKey($allowedIds))
            ->orderBy('name');
    }
}
