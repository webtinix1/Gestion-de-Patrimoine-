<?php

namespace App\Filament\Resources\Disposals\Schemas;

use App\Actions\Disposals\RecordDisposal;
use App\Enums\AssetStatus;
use App\Enums\DisposalReason;
use App\Filament\Resources\Disposals\Pages\CreateDisposal;
use App\Filament\Support\DocumentFields;
use App\Models\Asset;
use App\Models\DocumentType;
use App\Services\DisposalValuation;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\ToggleButtons;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\HtmlString;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * E26 : sortie d'un bien. Formulaire selon le motif (RG-51) et panneau des valeurs à la date de sortie,
 * calculées en direct avant la confirmation (RG-39). Aucune règle ici : tout passe par RecordDisposal.
 */
class DisposalForm
{
    /** Q21 : décideur en texte libre, avec ces suggestions. */
    public const array DECIDERS = ['Conseil d\'administration', 'Administrateur', 'Pasteur principal'];

    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(3)
            ->components([
                Grid::make(1)
                    ->columnSpan(['default' => 3, 'lg' => 2])
                    ->schema([self::asset(), self::decision(), self::attachments()]),
                Grid::make(1)
                    ->columnSpan(['default' => 3, 'lg' => 1])
                    ->schema([self::values(), self::after()]),
            ]);
    }

    /**
     * Biens que l'utilisateur peut sortir : dans son périmètre (scope), pas encore sortis.
     *
     * @return Builder<Asset>
     */
    public static function eligibleAssets(): Builder
    {
        return Asset::query()->whereNull('disposed_at');
    }

    private static function asset(): Section
    {
        return Section::make('Bien concerné')
            ->schema([
                Select::make('asset_id')
                    ->label('Bien')
                    ->required()
                    ->searchable()
                    ->live()
                    ->getSearchResultsUsing(fn (string $search): array => self::eligibleAssets()
                        ->where(fn (Builder $query) => $query->where('number', 'like', '%'.$search.'%')->orWhere('designation', 'like', '%'.$search.'%'))
                        ->orderBy('number')
                        ->limit(50)
                        ->get()
                        ->mapWithKeys(fn (Asset $asset): array => [$asset->getKey() => $asset->number.' · '.$asset->label()])
                        ->all())
                    ->getOptionLabelUsing(fn (mixed $value): ?string => ($asset = self::eligibleAssets()->find($value)) instanceof Asset ? $asset->number.' · '.$asset->label() : null)
                    ->afterStateUpdated(function (mixed $state, CreateDisposal $livewire): void {
                        $livewire->rememberAssetVersion(is_numeric($state) ? (int) $state : null);
                    }),
                Text::make(fn (Get $get): HtmlString => self::assetSummary(self::selectedAsset($get))),
            ]);
    }

    private static function decision(): Section
    {
        return Section::make('Motif de sortie')
            ->columns(3)
            ->schema([
                ToggleButtons::make('reason')
                    ->label('Motif')
                    ->options(DisposalReason::class)
                    ->inline()
                    ->required()
                    ->live()
                    ->columnSpanFull(),
                DatePicker::make('disposed_on')
                    ->label('Date de sortie')
                    ->default(now())
                    ->maxDate(now())
                    ->required()
                    ->live(),
                TextInput::make('decided_by')
                    ->label('Décision prise par')
                    ->datalist(self::DECIDERS)
                    ->required()
                    ->maxLength(255),
                TextInput::make('decision_reference')
                    ->label('Référence de la décision')
                    ->placeholder('PV CA du 24/10/2026')
                    ->maxLength(255),
                TextInput::make('buyer_or_beneficiary')
                    ->label(fn (Get $get): string => self::reason($get)?->counterpartyLabel() ?? 'Acquéreur')
                    ->visible(fn (Get $get): bool => self::reason($get)?->requiresCounterparty() ?? false)
                    ->required(fn (Get $get): bool => self::reason($get)?->requiresCounterparty() ?? false)
                    ->maxLength(255)
                    ->columnSpan(2),
                TextInput::make('sale_price')
                    ->label('Prix de cession (FCFA)')
                    ->visible(fn (Get $get): bool => self::reason($get)?->requiresPrice() ?? false)
                    ->required(fn (Get $get): bool => self::reason($get)?->requiresPrice() ?? false)
                    ->integer()
                    ->minValue(0)
                    ->maxValue(RecordDisposal::MAX_PRICE)
                    ->live(onBlur: true),
                Textarea::make('notes')
                    ->label('Observations')
                    ->placeholder(fn (Get $get): string => in_array(self::reason($get), [DisposalReason::Loss, DisposalReason::Theft], true)
                        ? 'Date du constat, référence de la déclaration…'
                        : '')
                    ->rows(3)
                    ->maxLength(5000)
                    ->columnSpanFull(),
            ]);
    }

    private static function attachments(): Section
    {
        return Section::make('Justificatifs')
            ->description('Décision, procès-verbal, déclaration de vol ou de perte… Rattachés à la sortie et à la fiche du bien.')
            ->columns(2)
            ->schema([
                DocumentFields::files()->required(false)->live()->columnSpanFull(),
                DocumentFields::type()
                    ->default(fn (): mixed => DocumentType::query()->active()->where('label', 'Document de réforme')->value('id'))
                    ->required(fn (Get $get): bool => filled($get('files')))
                    ->visible(fn (Get $get): bool => filled($get('files'))),
            ]);
    }

    private static function values(): Section
    {
        return Section::make('Valeurs à la date de sortie')
            ->schema([
                Text::make(fn (Get $get): HtmlString => self::valuesPanel($get)),
            ]);
    }

    private static function after(): Section
    {
        return Section::make('Après confirmation')
            ->schema([
                Text::make(fn (Get $get): HtmlString => self::afterPanel($get)),
            ]);
    }

    public static function selectedAsset(Get $get): ?Asset
    {
        $id = $get('asset_id');

        return is_numeric($id) ? self::eligibleAssets()->with(['site', 'department'])->find((int) $id) : null;
    }

    public static function reason(Get $get): ?DisposalReason
    {
        $value = $get('reason');

        return $value instanceof DisposalReason ? $value : DisposalReason::tryFrom(is_string($value) ? $value : '');
    }

    private static function date(Get $get): ?CarbonImmutable
    {
        $value = $get('disposed_on');

        try {
            return is_string($value) && $value !== '' ? CarbonImmutable::parse($value)->startOfDay() : null;
        } catch (Throwable) {
            return null;
        }
    }

    private static function assetSummary(?Asset $asset): HtmlString
    {
        if (! $asset instanceof Asset) {
            return new HtmlString('<p class="text-sm text-gray-600">Choisissez le bien à sortir du patrimoine (numéro ou désignation).</p>');
        }

        $warning = in_array($asset->status, [AssetStatus::OnLoan, AssetStatus::InMaintenance], true)
            ? '<p class="mt-2 text-sm font-semibold text-danger-600">Ce bien est '.e(mb_strtolower($asset->status->getLabel())).' : clôturez d\'abord '
                .($asset->status === AssetStatus::OnLoan ? 'le prêt' : 'l\'intervention').' avant de le sortir.</p>'
            : '';

        return new HtmlString('<div class="text-sm"><span class="pat-mono font-semibold text-primary-600">'.e($asset->number).'</span> · <strong>'.e($asset->label()).'</strong> · '
            .e($asset->site->name.' · '.$asset->department->name).' · '.e($asset->status->getLabel()).'</div>'.$warning);
    }

    private static function valuesPanel(Get $get): HtmlString
    {
        $asset = self::selectedAsset($get);
        $date = self::date($get);

        if (! $asset instanceof Asset || $date === null) {
            return new HtmlString('<p class="text-sm text-gray-600">Choisissez le bien et la date de sortie pour voir ses valeurs.</p>');
        }

        try {
            $values = app(DisposalValuation::class)->preview($asset, $date);
        } catch (ValidationException $exception) {
            return new HtmlString('<p class="text-sm text-danger-600">'.e(collect($exception->errors())->flatten()->implode(' ')).'</p>');
        }

        $rows = [
            'Valeur d\'origine' => Money::format($values->originalValue),
            'Mise en service' => $asset->in_service_on?->format('d/m/Y') ?? '—',
            'Durée · méthode' => $asset->is_depreciable && $asset->duration_years
                ? $asset->duration_years.' ans · '.mb_strtolower((string) $asset->depreciation_method?->getLabel())
                : 'Non amortissable',
        ];

        if ($values->hasPlan) {
            $rows['Dotation '.$date->year.' arrêtée au '.$date->format('d/m/Y')] = Money::format($values->yearAmount);
        }

        $rows['Amortissements cumulés au '.$date->format('d/m/Y')] = Money::format($values->accumulatedDepreciation);
        $rows['VNC à la sortie'] = Money::format($values->netBookValue);

        $html = '<dl class="divide-y divide-gray-100 text-sm">'.collect($rows)->map(fn (string $value, string $label): string => '<div class="flex justify-between gap-3 py-2"><dt>'.e($label).'</dt><dd class="pat-mono font-semibold">'.e($value).'</dd></div>')->join('').'</dl>';

        $price = $get('sale_price');

        if (self::reason($get) === DisposalReason::Sale && is_numeric($price)) {
            $gain = $values->indicativeGain((int) $price);
            $html .= '<div class="mt-3 flex justify-between rounded-lg px-3 py-2 text-sm '.($gain < 0 ? 'bg-danger-50 text-danger-700' : 'bg-success-50 text-success-700').'"><span>'
                .($gain < 0 ? 'Moins-value indicative' : 'Plus-value indicative').'</span><b class="pat-mono">'.e(Money::format(abs((int) $gain))).'</b></div>';
        }

        if (! $values->hasPlan) {
            $html .= '<p class="mt-2 text-xs text-gray-600">Bien sans plan d\'amortissement : aucun amortissement cumulé, la VNC est la valeur d\'origine.</p>';
        }

        return new HtmlString($html.'<p class="mt-3 text-xs text-gray-600">Ces valeurs seront figées sur la sortie. Le traitement comptable restera « à valider » jusqu\'à la validation du comptable.</p>');
    }

    private static function afterPanel(Get $get): HtmlString
    {
        $asset = self::selectedAsset($get);
        $reason = self::reason($get);
        $date = self::date($get);

        $items = [
            'Statut du bien : '.($reason?->resultingStatus()->getLabel() ?? 'Cédé (cession, don) ou Réformé (autres motifs)'),
            $asset?->is_depreciable ? 'Plan d\'amortissement arrêté au '.($date?->format('d/m/Y') ?? 'jour de la sortie') : 'Fiche conservée en lecture seule',
            'Le numéro '.($asset->number ?? 'du bien').' ne sera jamais réattribué',
            'La sortie ne pourra pas être annulée',
        ];

        return new HtmlString('<ul class="list-disc space-y-1 ps-5 text-sm">'.collect($items)->map(fn (string $item): string => '<li>'.e($item).'</li>')->join('').'</ul>');
    }
}
