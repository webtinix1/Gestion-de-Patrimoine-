<?php

namespace App\Filament\Pages;

use App\Actions\Labels\PrintLabels;
use App\Enums\LabelSheetLayout;
use App\Filament\Resources\LabelTemplates\LabelTemplateResource;
use App\Models\Asset;
use App\Models\LabelTemplate;
use App\Services\Labels\LabelSheetBuilder;
use App\Support\Labels\LabelContent;
use App\Support\Labels\SheetSlot;
use BackedEnum;
use DomainException;
use Filament\Actions\Action;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Concerns\InteractsWithSchemas;
use Filament\Schemas\Contracts\HasSchemas;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\StreamedResponse;
use UnitEnum;

/**
 * E09 · RG-95 à RG-97 : sélection des biens, modèle, support, case de départ, aperçu, PDF à l'échelle exacte.
 * Adresse /admin/etiquettes?biens=12,13,14 : présélectionne ces biens (identifiants séparés par des virgules).
 *
 * @property-read Schema $form
 * @property-read Schema $selectionForm
 */
class LabelPrinting extends Page implements HasSchemas
{
    use InteractsWithSchemas;

    public const string SUPPORT_SHEET = 'sheet';

    public const string SUPPORT_ROLL = 'roll';

    /** Au-delà, l'aperçu ne montre que les premières pages. */
    private const int PREVIEW_PAGES = 2;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedPrinter;

    protected static string|UnitEnum|null $navigationGroup = 'Patrimoine';

    protected static ?int $navigationSort = 3;

    protected static ?string $navigationLabel = 'Étiquettes';

    protected static ?string $title = 'Impression des étiquettes';

    protected static ?string $slug = 'etiquettes';

    protected string $view = 'filament.pages.label-printing';

    /** @var array<string, mixed> */
    public array $data = [];

    /** @var list<int> */
    public array $assetIds = [];

    protected ?string $previewError = null;

    public static function canAccess(): bool
    {
        return Gate::allows('viewAny', LabelTemplate::class);
    }

    public function mount(): void
    {
        $this->form->fill([
            'support' => self::SUPPORT_SHEET,
            'template_id' => $this->defaultTemplateId(self::SUPPORT_SHEET),
            'start_cell' => 1,
            'copies' => 1,
        ]);

        $raw = (string) request()->query('biens', '');
        $tokens = count(array_filter(array_map('trim', explode(',', $raw)), fn (string $token): bool => $token !== ''));
        $requested = $this->requestedIds($raw);

        if ($tokens > 0) {
            $this->assetIds = array_slice($this->visibleIds($requested), 0, PrintLabels::MAX_LABELS);

            $ignored = $tokens - count($this->assetIds);

            if ($ignored > 0) {
                Notification::make()
                    ->warning()
                    ->title($ignored === 1 ? '1 bien n\'a pas été retenu' : $ignored.' biens n\'ont pas été retenus')
                    ->body('Ils sont introuvables, sortis du patrimoine ou hors de votre périmètre.')
                    ->send();
            }
        }
    }

    public function selectionForm(Schema $schema): Schema
    {
        return $schema
            ->statePath('data')
            ->components([
                TextInput::make('scan')
                    ->label('Scanner ou saisir un numéro pour l\'ajouter')
                    ->placeholder('PAT-000001')
                    ->autocomplete(false)
                    ->extraInputAttributes(['class' => 'pat-mono', 'wire:keydown.enter.prevent' => 'addByNumber']),
                Select::make('add_asset')
                    ->label('Ou chercher un bien')
                    ->placeholder('Numéro, désignation ou numéro de série')
                    ->searchable()
                    ->getSearchResultsUsing(fn (string $search): array => $this->searchAssets($search))
                    ->live()
                    ->afterStateUpdated(function (Set $set, mixed $state): void {
                        if (is_numeric($state)) {
                            $this->addAsset((int) $state);
                        }

                        $set('add_asset', null);
                    }),
            ]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->statePath('data')
            ->components([
                Radio::make('support')
                    ->label('Support')
                    ->options([
                        self::SUPPORT_SHEET => 'Planche A4',
                        self::SUPPORT_ROLL => 'Rouleau',
                    ])
                    ->descriptions([
                        self::SUPPORT_SHEET => 'Planche d\'étiquettes autocollantes, 3 × 8 par défaut',
                        self::SUPPORT_ROLL => 'Imprimante d\'étiquettes, 50 × 25 mm par défaut',
                    ])
                    ->live()
                    ->afterStateUpdated(function (Set $set, ?string $state): void {
                        $set('template_id', $this->defaultTemplateId((string) $state));
                        $set('start_cell', 1);
                    }),
                Select::make('template_id')
                    ->label('Modèle')
                    ->options(fn (Get $get): array => $this->templatesFor((string) $get('support'))
                        ->mapWithKeys(fn (LabelTemplate $template): array => [$template->id => $template->name.' · '.$template->summary()])
                        ->all())
                    ->required()
                    ->native(false)
                    ->live()
                    ->afterStateUpdated(fn (Set $set) => $set('start_cell', 1)),
                TextInput::make('copies')
                    ->label('Copies par bien')
                    ->numeric()
                    ->integer()
                    ->minValue(1)
                    ->maxValue(50)
                    ->required()
                    ->live(onBlur: true),
                TextInput::make('start_cell')
                    ->label('Commencer à la case')
                    ->numeric()
                    ->integer()
                    ->minValue(1)
                    ->maxValue(fn (): int => $this->perPage())
                    ->required()
                    ->live(onBlur: true)
                    ->visible(fn (): bool => $this->isSheet())
                    ->helperText(fn (): string => 'Pour réutiliser une planche déjà entamée : les cases avant la case de départ restent vides. De 1 à '.$this->perPage().'.'),
            ]);
    }

    /**
     * @return array<Action>
     */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('templates')
                ->label('Modifier le modèle')
                ->color('gray')
                ->icon('heroicon-o-pencil-square')
                ->url(fn (): string => LabelTemplateResource::getUrl())
                ->visible(fn (): bool => LabelTemplateResource::canViewAny()),
            Action::make('generate')
                ->label('Générer le PDF')
                ->icon('heroicon-o-arrow-down-tray')
                ->visible(fn (): bool => Gate::allows('print', LabelTemplate::class))
                ->disabled(fn (): bool => $this->assetIds === [])
                ->action(fn (): ?StreamedResponse => $this->generate()),
        ];
    }

    public function generate(): ?StreamedResponse
    {
        $this->form->validate();

        $template = $this->template();

        if ($template === null) {
            return null;
        }

        try {
            $printed = app(PrintLabels::class)($template, $this->assetIds, $this->copies(), $this->startCell());
        } catch (DomainException $exception) {
            Notification::make()->danger()->title($exception->getMessage())->send();

            return null;
        }

        Notification::make()
            ->success()
            ->title('PDF généré')
            ->body($printed->labelCount.' étiquette'.($printed->labelCount > 1 ? 's' : '').' sur '.$printed->pageCount.($template->sheet_layout->isSheet() ? ' planche' : ' page').($printed->pageCount > 1 ? 's' : '').'. Imprimez-le à 100 %, sans mise à l\'échelle.')
            ->send();

        return response()->streamDownload(
            function () use ($printed): void {
                echo $printed->pdf;
            },
            $printed->fileName,
            ['Content-Type' => 'application/pdf'],
        );
    }

    /**
     * Le navigateur peut envoyer n'importe quoi : on ne garde que des identifiants entiers de biens visibles.
     */
    public function updatedAssetIds(): void
    {
        $this->assetIds = array_slice($this->visibleIds($this->integerIds($this->assetIds)), 0, PrintLabels::MAX_LABELS);
    }

    /**
     * @param  array<mixed>  $raw
     * @return list<int>
     */
    private function integerIds(array $raw): array
    {
        $ids = [];

        foreach ($raw as $id) {
            if ((is_int($id) || (is_string($id) && ctype_digit($id))) && (int) $id > 0) {
                $ids[] = (int) $id;
            }
        }

        return array_values(array_unique($ids));
    }

    public function addByNumber(): void
    {
        $number = mb_strtoupper(trim((string) ($this->data['scan'] ?? '')));
        $this->data['scan'] = null;

        if ($number === '') {
            return;
        }

        $asset = Asset::query()->where('number', $number)->first();

        if ($asset === null || $asset->isDisposed()) {
            Notification::make()->danger()->title('Aucun bien actif avec le numéro '.$number)->send();

            return;
        }

        $this->addAsset($asset->id);
    }

    public function addAsset(int $id): void
    {
        if (in_array($id, $this->assetIds, true) || $this->visibleIds([$id]) === []) {
            return;
        }

        $this->assetIds[] = $id;
    }

    public function removeAsset(int $id): void
    {
        $this->assetIds = array_values(array_filter($this->assetIds, fn (int $assetId): bool => $assetId !== $id));
    }

    public function clearAssets(): void
    {
        $this->assetIds = [];
    }

    /**
     * Résultats de la recherche par numéro, désignation ou numéro de série (biens actifs du périmètre).
     *
     * @return array<int, string>
     */
    public function searchAssets(string $term): array
    {
        $term = trim($term);

        return Asset::query()
            ->whereNull('disposed_at')
            ->when($term !== '', fn ($query) => $query->where(fn ($query) => $query
                ->where('number', 'like', '%'.$term.'%')
                ->orWhere('designation', 'like', '%'.$term.'%')
                ->orWhere('serial_number', 'like', '%'.$term.'%')))
            ->orderBy('number')
            ->limit(30)
            ->get()
            ->mapWithKeys(fn (Asset $asset): array => [$asset->id => $asset->number.' · '.$asset->label()])
            ->all();
    }

    /**
     * @return Collection<int, Asset>
     */
    public function selectedAssets(): Collection
    {
        $assets = Asset::query()
            ->with(['department', 'site', 'room'])
            ->whereIn('id', $this->assetIds)
            ->whereNull('disposed_at')
            ->get()
            ->keyBy('id');

        return collect($this->assetIds)->filter(fn (int $id): bool => $assets->has($id))->map(fn (int $id): Asset => $assets->get($id))->values();
    }

    public function template(): ?LabelTemplate
    {
        $id = $this->data['template_id'] ?? null;

        return is_numeric($id) ? LabelTemplate::query()->find((int) $id) : null;
    }

    public function isSheet(): bool
    {
        return ($this->data['support'] ?? self::SUPPORT_SHEET) === self::SUPPORT_SHEET;
    }

    public function perPage(): int
    {
        return $this->template()?->sheet_layout->perPage() ?? LabelSheetLayout::A4ThreeByEight->perPage();
    }

    public function copies(): int
    {
        return max(1, min(50, (int) ($this->data['copies'] ?? 1)));
    }

    public function startCell(): int
    {
        $template = $this->template();

        if ($template === null || ! $template->sheet_layout->isSheet()) {
            return 1;
        }

        return max(1, min($template->sheet_layout->perPage(), (int) ($this->data['start_cell'] ?? 1)));
    }

    /**
     * Aperçu : les mêmes pages que le PDF, avec des codes vectoriels, limitées aux premières planches.
     *
     * @return array{pages: list<list<SheetSlot>>, width: float, height: float, total: int, shown: int, info: string}|null
     */
    public function preview(): ?array
    {
        $this->previewError = null;

        $template = $this->template();
        $assets = $this->selectedAssets();

        if ($template === null || $assets->isEmpty()) {
            return null;
        }

        $builder = app(LabelSheetBuilder::class);

        if (! $builder->fits($template)) {
            return null;
        }

        $labels = [];

        foreach ($assets as $asset) {
            for ($copy = 0; $copy < $this->copies(); $copy++) {
                $labels[] = LabelContent::fromAsset($asset);
            }
        }

        $total = count($labels);
        $start = $this->startCell();
        $perPage = $builder->perPage($template);
        $pageCount = (int) ceil(($start - 1 + $total) / $perPage);

        // Pour un rouleau, chaque étiquette est une page : on n'en montre que quelques-unes.
        $limit = $template->sheet_layout->isSheet() ? $perPage * self::PREVIEW_PAGES - ($start - 1) : 8;
        $shown = min($total, max(1, $limit));

        try {
            $pages = $builder->pages($template, array_slice($labels, 0, $shown), $start, vector: true);
        } catch (DomainException $exception) {
            $this->previewError = $exception->getMessage();

            return null;
        }

        [$width, $height] = $builder->pageSize($template);

        $info = $template->sheet_layout->isSheet()
            ? $pageCount.($pageCount > 1 ? ' planches' : ' planche').' A4 · '.$total.' étiquette'.($total > 1 ? 's' : '').' · '.$builder->cellRange($template, $total, $start)
            : $total.' étiquette'.($total > 1 ? 's' : '').' '.$template->summary();

        return ['pages' => $pages, 'width' => $width, 'height' => $height, 'total' => $total, 'shown' => $shown, 'info' => $info];
    }

    public function previewError(): ?string
    {
        return $this->previewError;
    }

    public function tooBig(): bool
    {
        $template = $this->template();

        return $template !== null && ! app(LabelSheetBuilder::class)->fits($template);
    }

    /**
     * @return Collection<int, LabelTemplate>
     */
    private function templatesFor(string $support): Collection
    {
        $sheet = $support !== self::SUPPORT_ROLL;

        return LabelTemplate::query()
            ->orderByDesc('is_default')
            ->orderBy('name')
            ->get()
            ->filter(fn (LabelTemplate $template): bool => $template->sheet_layout->isSheet() === $sheet)
            ->values();
    }

    private function defaultTemplateId(string $support): ?int
    {
        return $this->templatesFor($support)->first()?->id;
    }

    /**
     * @return list<int>
     */
    private function requestedIds(string $raw): array
    {
        return collect(explode(',', $raw))
            ->map(fn (string $id): string => trim($id))
            ->filter(fn (string $id): bool => ctype_digit($id) && (int) $id > 0)
            ->map(fn (string $id): int => (int) $id)
            ->unique()
            ->values()
            ->all();
    }

    /**
     * Garde, dans l'ordre demandé, les biens actifs du périmètre de l'utilisateur (RG-81).
     *
     * @param  list<int>  $ids
     * @return list<int>
     */
    private function visibleIds(array $ids): array
    {
        $visible = Asset::query()->whereIn('id', $ids)->whereNull('disposed_at')->pluck('id')->all();

        return array_values(array_filter($ids, fn (int $id): bool => in_array($id, $visible, true)));
    }
}
