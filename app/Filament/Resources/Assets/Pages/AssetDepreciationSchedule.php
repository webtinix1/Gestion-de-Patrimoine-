<?php

namespace App\Filament\Resources\Assets\Pages;

use App\Actions\Depreciation\ChangeDepreciationParameters;
use App\Actions\Depreciation\ExportDepreciationSchedule;
use App\Actions\Depreciation\ValidateDepreciationSchedule;
use App\Enums\DepreciationMethod;
use App\Exceptions\ConcurrentModificationException;
use App\Filament\Resources\Assets\AssetResource;
use App\Models\Asset;
use App\Models\DepreciationSchedule;
use App\Models\User;
use App\Services\DepreciationPlanReport;
use App\Services\DepreciationRules;
use App\Services\FiscalYearCalendar;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\Concerns\InteractsWithRecord;
use Filament\Resources\Pages\Page;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

/**
 * E31 : plan d'amortissement d'un bien. Paramètres, tableau par exercice avec statut, évolution
 * de la VNC, historique des modifications, « Modifier les paramètres » (motif obligatoire, RG-37),
 * validation (RG-38) et exports PDF et Excel (E45).
 */
class AssetDepreciationSchedule extends Page
{
    use InteractsWithRecord;

    protected static string $resource = AssetResource::class;

    protected string $view = 'filament.resources.assets.pages.asset-depreciation-schedule';

    // RG-86 : version de la fiche lue à l'ouverture de la page.
    public int $openedVersion = 0;

    public static function canAccess(array $parameters = []): bool
    {
        $user = auth()->user();
        $record = $parameters['record'] ?? null;

        return $user instanceof User
            && $user->can('viewAny', DepreciationSchedule::class)
            && (! $record instanceof Asset || $user->can('view', $record));
    }

    public function mount(int|string $record): void
    {
        $this->record = $this->resolveRecord($record);
        $this->openedVersion = $this->asset()->lock_version;
    }

    public function asset(): Asset
    {
        /** @var Asset $asset */
        $asset = $this->getRecord();

        return $asset;
    }

    public function getTitle(): string|Htmlable
    {
        return 'Plan d\'amortissement · '.$this->asset()->number;
    }

    public function getSubheading(): string
    {
        $asset = $this->asset();

        return implode(' · ', array_filter([
            $asset->label(),
            $asset->category?->name,
            $asset->site->name.' › '.$asset->department->name,
        ]));
    }

    public function getBreadcrumb(): string
    {
        return 'Plan d\'amortissement';
    }

    /**
     * @return array<string, mixed>
     */
    public function getReport(): array
    {
        return app(DepreciationPlanReport::class)->build($this->asset());
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('back')
                ->label('Fiche '.$this->asset()->number)
                ->icon('heroicon-o-arrow-left')
                ->color('gray')
                ->url(AssetResource::getUrl('view', ['record' => $this->asset()])),
            Action::make('exportExcel')
                ->label('Exporter Excel')
                ->icon('heroicon-o-table-cells')
                ->color('gray')
                ->visible(fn (): bool => $this->canExport())
                ->action(fn (): Response => app(ExportDepreciationSchedule::class)->excel($this->asset())),
            Action::make('exportPdf')
                ->label('Exporter PDF')
                ->icon('heroicon-o-document-text')
                ->color('gray')
                ->visible(fn (): bool => $this->canExport())
                ->action(fn (): Response => app(ExportDepreciationSchedule::class)->pdf($this->asset())),
            Action::make('validate')
                ->label('Valider le plan')
                ->icon('heroicon-o-check-badge')
                ->color('success')
                ->visible(fn (): bool => ($schedule = $this->schedule()) !== null && (auth()->user()?->can('validate', $schedule) ?? false))
                ->requiresConfirmation()
                ->modalHeading('Valider le plan d\'amortissement')
                ->modalDescription('La version validée est conservée telle quelle (RG-38). Toute modification ultérieure créera une nouvelle version.')
                ->modalSubmitActionLabel('Valider')
                ->action(function (): void {
                    $schedule = $this->schedule();
                    $user = auth()->user();

                    if ($schedule === null || ! $user instanceof User) {
                        return;
                    }

                    app(ValidateDepreciationSchedule::class)($schedule, $user);
                    Notification::make()->title('Plan validé (version '.$schedule->version.')')->success()->send();
                }),
            $this->changeParametersAction(),
        ];
    }

    private function changeParametersAction(): Action
    {
        return Action::make('changeParameters')
            ->label('Modifier les paramètres')
            ->icon('heroicon-o-adjustments-horizontal')
            ->visible(fn (): bool => auth()->user()?->can('changeParameters', [DepreciationSchedule::class, $this->asset()]) ?? false)
            ->modalHeading('Modifier les paramètres d\'amortissement')
            ->modalDescription(fn (): string => $this->changeDescription())
            ->modalSubmitActionLabel('Recalculer et enregistrer')
            ->fillForm(fn (): array => [
                'duration_years' => $this->asset()->duration_years,
                'residual_value' => $this->asset()->residual_value,
                'acquisition_cost' => $this->asset()->acquisition_cost,
                'in_service_on' => $this->asset()->in_service_on?->toDateString(),
                'depreciation_method' => $this->asset()->depreciation_method?->value,
            ])
            ->schema([
                TextInput::make('duration_years')->label('Durée (années)')->integer()->minValue(1)->maxValue(100)->required(),
                TextInput::make('residual_value')->label('Valeur résiduelle (FCFA)')->integer()->minValue(0)->required(),
                TextInput::make('acquisition_cost')->label('Valeur d\'origine (FCFA)')->integer()->minValue(1)->maxValue(999_999_999_999)->required(),
                DatePicker::make('in_service_on')->label('Date de mise en service')->required(),
                Select::make('depreciation_method')
                    ->label('Méthode')
                    ->options(DepreciationMethod::class)
                    ->visible(fn (): bool => app(DepreciationRules::class)->decliningEnabled())
                    ->required(),
                Textarea::make('reason')->label('Motif (obligatoire)')->required()->minLength(3)->maxLength(1000)->rows(2),
            ])
            ->action(function (array $data, Action $action): void {
                $reason = (string) ($data['reason'] ?? '');
                unset($data['reason']);

                try {
                    app(ChangeDepreciationParameters::class)($this->asset(), $data, $reason, $this->openedVersion);
                } catch (ValidationException $exception) {
                    Notification::make()
                        ->title('Modification refusée')
                        ->body(collect($exception->errors())->flatten()->implode(' '))
                        ->danger()
                        ->persistent()
                        ->send();
                    $action->halt();
                } catch (ConcurrentModificationException $exception) {
                    Notification::make()->title('Fiche modifiée entre-temps')->body($exception->getMessage())->warning()->persistent()->send();
                    $action->halt();
                }

                $this->record = $this->resolveRecord($this->asset()->getKey());
                $this->openedVersion = $this->asset()->lock_version;

                Notification::make()->title('Paramètres modifiés et plan recalculé')->success()->send();
            });
    }

    private function changeDescription(): string
    {
        $calendar = new FiscalYearCalendar;
        $first = $calendar->firstRecalculableYear();
        $closed = $calendar->all()->filter->isClosed()->keys()->all();

        if ($first === null) {
            return 'Aucun exercice n\'est encore clôturé : tout le plan sera recalculé. L\'ancienne et la nouvelle valeur seront conservées dans l\'historique.';
        }

        $locked = $closed === [] ? 'Les exercices antérieurs à '.$first : (count($closed) > 1 ? 'Les exercices '.implode(', ', $closed) : 'L\'exercice '.$closed[0]);

        return $locked.' ne seront pas recalculés (exercices figés). La modification s\'applique à partir de l\'exercice '.$first.' : la VNC au 01/01/'.$first.' est répartie sur la durée restante. L\'ancienne et la nouvelle valeur seront conservées dans l\'historique.';
    }

    private function schedule(): ?DepreciationSchedule
    {
        return app(DepreciationPlanReport::class)->current($this->asset());
    }

    private function canExport(): bool
    {
        return $this->schedule() !== null && (auth()->user()?->can('export', DepreciationSchedule::class) ?? false);
    }
}
