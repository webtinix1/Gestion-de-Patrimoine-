<?php

namespace App\Filament\Pages\Depreciation;

use App\Enums\DepreciationMethod;
use App\Filament\Resources\Assets\AssetResource;
use App\Models\Asset;
use App\Models\DepreciationSchedule;
use App\Models\FiscalYear;
use App\Services\DepreciationRegister;
use App\Services\FiscalYearCalendar;
use App\Support\Money;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Grouping\Group;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Attributes\Url;
use UnitEnum;

/**
 * E30 : tableau des immobilisations par exercice. Sélecteur d'exercice, compteurs, onglets
 * (en cours, échéance atteinte, totalement amorties, non amortissables), regroupements et filtres,
 * récapitulatif par catégorie. Limité aux biens du périmètre de l'utilisateur (RG-81).
 */
class DepreciationRegisterPage extends Page implements HasTable
{
    use InteractsWithTable;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCalculator;

    protected static string|UnitEnum|null $navigationGroup = 'Immobilisations';

    protected static ?int $navigationSort = 1;

    protected static ?string $navigationLabel = 'Tableau des immobilisations';

    protected static ?string $title = 'Tableau des immobilisations';

    protected static ?string $slug = 'immobilisations';

    protected string $view = 'filament.pages.depreciation-register';

    #[Url(as: 'exercice')]
    public ?int $year = null;

    #[Url(as: 'onglet')]
    public string $tab = DepreciationRegister::TAB_CURRENT;

    public static function canAccess(): bool
    {
        return auth()->user()?->can('viewAny', DepreciationSchedule::class) ?? false;
    }

    public function mount(): void
    {
        $this->year ??= (new FiscalYearCalendar)->current()->year ?? now()->year;

        if (! array_key_exists($this->tab, DepreciationRegister::TABS)) {
            $this->tab = DepreciationRegister::TAB_CURRENT;
        }
    }

    public function updatedYear(): void
    {
        $this->resetTable();
    }

    public function selectTab(string $tab): void
    {
        $this->tab = array_key_exists($tab, DepreciationRegister::TABS) ? $tab : DepreciationRegister::TAB_CURRENT;
        $this->resetTable();
    }

    public function getSubheading(): string
    {
        $fiscalYear = (new FiscalYearCalendar)->find($this->year());
        $state = match (true) {
            $fiscalYear === null => 'prévisionnel',
            $fiscalYear->isClosed() => 'clôturé',
            default => mb_strtolower($fiscalYear->status->getLabel()),
        };

        return 'Exercice '.$this->year().' (01/01 au 31/12/'.$this->year().'), '.$state.' · valeurs au 31/12/'.$this->year().' · montants en FCFA';
    }

    /**
     * @return array<int, string>
     */
    public function getYearOptions(): array
    {
        $years = FiscalYear::query()->orderByDesc('year')->get();
        $options = [];
        $next = ((int) $years->max('year') ?: now()->year) + 1;
        $options[$next] = 'Exercice '.$next.' (prévisionnel)';

        foreach ($years as $fiscalYear) {
            $options[$fiscalYear->year] = 'Exercice '.$fiscalYear->year.($fiscalYear->isClosed() ? ' (clôturé)' : '');
        }

        if (! array_key_exists($this->year(), $options)) {
            $options[$this->year()] = 'Exercice '.$this->year();
        }

        krsort($options);

        return $options;
    }

    /**
     * @return array{totals: array<string, int>, categories: list<array<string, mixed>>}
     */
    public function getSummary(): array
    {
        return app(DepreciationRegister::class)->summary(app(DepreciationRegister::class)->query($this->year()), $this->year());
    }

    public function table(Table $table): Table
    {
        $year = $this->year();
        $money = fn (mixed $state): string => $state === null ? '·' : Money::format((int) $state);

        return $table
            ->query(fn (): Builder => app(DepreciationRegister::class)->applyTab(app(DepreciationRegister::class)->query($year), $this->tab, $year))
            ->defaultSort('number')
            ->columns([
                TextColumn::make('number')
                    ->label('N°')
                    ->fontFamily('mono')
                    ->weight('semibold')
                    ->color('primary')
                    ->searchable()
                    ->sortable()
                    ->url(fn (Asset $record): string => AssetResource::getUrl('depreciation', ['record' => $record])),
                TextColumn::make('designation')->label('Désignation')->searchable(),
                TextColumn::make('category.name')->label('Catégorie'),
                TextColumn::make('in_service_on')->label('Mise en service')->date()->placeholder('·'),
                TextColumn::make('duration_years')->label('Durée')->suffix(' ans')->placeholder('·'),
                TextColumn::make('acquisition_cost')->label('Valeur d\'origine')->alignEnd()->fontFamily('mono')->formatStateUsing($money),
                TextColumn::make('year_amount')->label('Dotation '.$year)->alignEnd()->fontFamily('mono')->formatStateUsing($money)->placeholder('·'),
                TextColumn::make('accumulated_to_year')->label('Cumul')->alignEnd()->fontFamily('mono')->formatStateUsing($money)->placeholder('·'),
                TextColumn::make('net_book_value')
                    ->label('VNC')
                    ->alignEnd()
                    ->fontFamily('mono')
                    ->weight('semibold')
                    ->state(fn (Asset $record): ?int => $record->getAttribute('schedule_id') === null || $record->getAttribute('accumulated_to_year') === null
                        ? null
                        : (int) $record->getAttribute('schedule_original_value') - (int) $record->getAttribute('accumulated_to_year'))
                    ->formatStateUsing($money)
                    ->placeholder('·'),
                TextColumn::make('progress')
                    ->label('Avancement')
                    ->state(function (Asset $record): ?string {
                        $base = (int) $record->getAttribute('schedule_base');

                        return $base > 0 ? intdiv((int) $record->getAttribute('accumulated_to_year') * 100, $base).' %' : null;
                    })
                    ->placeholder('·'),
            ])
            ->groups([
                Group::make('asset_category_id')->label('Catégorie')->getTitleFromRecordUsing(fn (Asset $record): string => $record->category->name),
                Group::make('site_id')->label('Site')->getTitleFromRecordUsing(fn (Asset $record): string => $record->site->name),
                Group::make('department_id')->label('Département')->getTitleFromRecordUsing(fn (Asset $record): string => $record->department->name),
            ])
            ->filters([
                SelectFilter::make('asset_category_id')->label('Catégorie')->relationship('category', 'name'),
                SelectFilter::make('site_id')->label('Site')->relationship('site', 'name'),
                SelectFilter::make('department_id')->label('Département')->relationship('department', 'name'),
                SelectFilter::make('method')
                    ->label('Méthode')
                    ->options(DepreciationMethod::class)
                    ->query(fn (Builder $query, array $data): Builder => filled($data['value'] ?? null) ? $query->where('ds.method', $data['value']) : $query),
            ])
            ->emptyStateHeading('Aucun bien dans cet onglet pour l\'exercice '.$year);
    }

    private function year(): int
    {
        return $this->year ?? now()->year;
    }
}
