<?php

namespace App\Filament\Pages;

use App\Actions\Reporting\ExportReportToExcel;
use App\Actions\Reporting\ExportReportToPdf;
use App\Actions\Reporting\GenerateReport;
use App\Models\AssetCategory;
use App\Models\Department;
use App\Models\FiscalYear;
use App\Models\Holder;
use App\Models\Service;
use App\Models\Site;
use App\Models\User;
use App\Support\Reports\ReportCatalog;
use App\Support\Reports\ReportData;
use App\Support\Reports\ReportDefinition;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Livewire\Attributes\Url;
use Symfony\Component\HttpFoundation\Response;
use UnitEnum;

/**
 * E29 : catalogue des rapports, filtres communs (exigence 15.3), aperçu, export PDF et Excel.
 * Chaque rapport exige le droit « exporter » de son module (QL11-1) et ne lit que le périmètre de l'utilisateur.
 */
class Reports extends Page
{
    public const int PREVIEW_ROWS = 25;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentChartBar;

    protected static string|UnitEnum|null $navigationGroup = 'Pilotage';

    protected static ?int $navigationSort = 1;

    protected static ?string $navigationLabel = 'Rapports';

    protected static ?string $title = 'Rapports et états';

    protected static ?string $slug = 'rapports';

    protected string $view = 'filament.pages.reports';

    #[Url(as: 'rapport')]
    public ?string $report = null;

    /** @var array<string, mixed> saisie en cours */
    public array $filters = [];

    /** @var array<string, mixed> filtres appliqués à l'aperçu et aux exports */
    public array $applied = [];

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return $user instanceof User && ReportCatalog::availableTo($user) !== [];
    }

    public function mount(): void
    {
        if ($this->report === null) {
            $first = collect(ReportCatalog::availableTo($this->user()))->flatten()->first();
            $this->report = $first instanceof ReportDefinition ? $first->key : null;
        }

        $this->authorizeReport();
    }

    public function updatedReport(): void
    {
        $this->authorizeReport();
        $this->filters = [];
        $this->applied = [];
    }

    public function apply(): void
    {
        $this->applied = $this->filters;
    }

    public function selectReport(string $key): void
    {
        $this->report = $key;
        $this->updatedReport();
    }

    public function getSubheading(): ?string
    {
        return 'Choisissez un rapport, appliquez les filtres, puis exportez en PDF ou Excel.';
    }

    public function definition(): ?ReportDefinition
    {
        return $this->report === null ? null : ReportCatalog::find($this->report);
    }

    /**
     * @return array<string, list<ReportDefinition>>
     */
    public function catalog(): array
    {
        return ReportCatalog::availableTo($this->user());
    }

    public function canExport(): bool
    {
        $definition = $this->definition();

        return $definition !== null && ReportCatalog::canExport($this->user(), $definition);
    }

    public function preview(): ?ReportData
    {
        $definition = $this->definition();

        if ($definition === null) {
            return null;
        }

        return app(GenerateReport::class)($this->user(), $definition->key, $this->applied);
    }

    /**
     * Listes des filtres : seuls les éléments du périmètre de l'utilisateur sont proposés.
     *
     * @return array<string, array<int|string, string>>
     */
    public function options(): array
    {
        $names = fn (string $model) => $model::query()->orderBy('name')->pluck('name', 'id')->all();

        return [
            'site' => $names(Site::class),
            'department' => $names(Department::class),
            'service' => $names(Service::class),
            'category' => $names(AssetCategory::class),
            // Catégories et exercices sont des référentiels communs ; les responsables suivent le périmètre (RG-81).
            'holder' => Holder::query()->inPerimeter()->orderBy('name')->pluck('name', 'id')->all(),
            'year' => FiscalYear::query()->orderByDesc('year')->pluck('year', 'year')->map(fn ($year): string => (string) $year)->all(),
            'status' => $this->definition()?->statusOptions() ?? [],
        ];
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('exportExcel')
                ->label('Exporter Excel')
                ->icon('heroicon-o-table-cells')
                ->color('gray')
                ->visible(fn (): bool => $this->canExport())
                ->action(fn (): Response => app(ExportReportToExcel::class)($this->user(), (string) $this->report, $this->applied)),
            Action::make('exportPdf')
                ->label('Exporter PDF')
                ->icon('heroicon-o-document-text')
                ->visible(fn (): bool => $this->canExport())
                ->action(fn (): Response => app(ExportReportToPdf::class)($this->user(), (string) $this->report, $this->applied)),
        ];
    }

    private function authorizeReport(): void
    {
        $definition = $this->definition();

        abort_if($definition === null || ! ReportCatalog::canView($this->user(), $definition), 403, 'Vous n\'avez pas accès à ce rapport.');
    }

    private function user(): User
    {
        $user = auth()->user();
        abort_unless($user instanceof User, 403);

        return $user;
    }
}
