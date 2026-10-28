<?php

namespace App\Filament\Resources\Inventories\Pages;

use App\Actions\Inventories\ExportInventoryReport;
use App\Actions\Inventories\RecordInventoryEntry;
use App\Actions\Inventories\SubmitInventory;
use App\Enums\InventoryLineResult;
use App\Exceptions\InventoryLineConflictException;
use App\Filament\Resources\Assets\AssetResource;
use App\Filament\Resources\Inventories\InventoryResource;
use App\Models\AssetCondition;
use App\Models\Department;
use App\Models\Inventory;
use App\Models\InventoryLine;
use App\Models\Room;
use App\Models\Site;
use App\Models\User;
use App\Services\Inventories\InventoryReport;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\Concerns\InteractsWithRecord;
use Filament\Resources\Pages\Page;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
use Livewire\WithPagination;
use Symfony\Component\HttpFoundation\Response;

/**
 * E20 : saisie d'un inventaire, au plus près de la maquette. Champ autofocus pour la douchette
 * (code + Entrée), local contrôlé, compteurs à jour (rafraîchis toutes les 15 s pour voir les saisies
 * des autres postes), liste des biens attendus avec Présent, Manquant, Autre affectation, état constaté,
 * biens trouvés non enregistrés. Aucune règle ici : tout passe par RecordInventoryEntry, qui revérifie
 * droits, périmètre et version de la ligne (RG-47, RG-81).
 */
class EnterInventory extends Page
{
    use InteractsWithRecord;
    use WithPagination;

    protected static string $resource = InventoryResource::class;

    protected string $view = 'filament.resources.inventories.pages.enter-inventory';

    public string $code = '';

    public ?int $roomId = null;

    public string $search = '';

    public string $resultFilter = '';

    /** @var array{status: string, tone: string, message: string}|null */
    public ?array $lastOutcome = null;

    public static function canAccess(array $parameters = []): bool
    {
        $user = auth()->user();
        $record = $parameters['record'] ?? null;

        return $user instanceof User && (! $record instanceof Inventory || $user->can('enter', $record));
    }

    public function mount(int|string $record): void
    {
        $this->record = $this->resolveRecord($record);
        $user = auth()->user();
        $inventory = $this->inventory();

        abort_unless($user instanceof User && $user->can('enter', $inventory), 403);

        $this->roomId = $inventory->participants()->where('user_id', $user->id)->value('room_id');
    }

    public function inventory(): Inventory
    {
        /** @var Inventory $inventory */
        $inventory = $this->getRecord();

        return $inventory;
    }

    public function getTitle(): string|Htmlable
    {
        $inventory = $this->inventory();

        return 'Saisie · '.$inventory->scopeLabel();
    }

    public function getSubheading(): string
    {
        $inventory = $this->inventory();

        return $inventory->reference.' · du '.$inventory->periodLabel().' · enregistrement automatique';
    }

    public function getBreadcrumb(): string
    {
        return 'Saisie';
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedResultFilter(): void
    {
        $this->resetPage();
    }

    /**
     * Douchette : le lecteur tape le numéro puis Entrée.
     */
    public function scan(): void
    {
        try {
            $outcome = app(RecordInventoryEntry::class)->scan($this->inventory(), $this->user(), $this->code, $this->roomId);
            $this->lastOutcome = ['status' => $outcome->status, 'tone' => $outcome->tone(), 'message' => $outcome->message];
        } catch (ValidationException $exception) {
            $this->lastOutcome = ['status' => 'error', 'tone' => 'danger', 'message' => (string) collect($exception->errors())->flatten()->first()];
        }

        $this->code = '';
        $this->dispatch('inventory-scanned');
    }

    /**
     * Boutons Présent et Manquant ; $version : version de la ligne affichée (RG-47).
     */
    public function mark(int $lineId, string $intent, int $version): void
    {
        if (! in_array($intent, ['present', 'missing'], true)) {
            return;
        }

        $this->record($lineId, $intent, [], $version);
    }

    public function setCondition(int $lineId, mixed $conditionId, int $version): void
    {
        if (blank($conditionId)) {
            return;
        }

        $this->record($lineId, 'condition', ['condition_id' => $conditionId], $version);
    }

    /**
     * @return array<string, int>
     */
    public function getCounters(): array
    {
        return app(InventoryReport::class)->counters($this->inventory());
    }

    /**
     * @return LengthAwarePaginator<int, InventoryLine>
     */
    public function getLines(): LengthAwarePaginator
    {
        $search = trim($this->search);
        $result = InventoryLineResult::tryFrom($this->resultFilter);

        return app(InventoryReport::class)->lines($this->inventory())
            ->where('result', '!=', InventoryLineResult::Unregistered)
            ->when($result, fn (Builder $query, InventoryLineResult $result) => $query->where('result', $result))
            ->when($search !== '', fn (Builder $query) => $query->whereHas('asset', fn (Builder $query) => $query
                ->where('number', 'like', '%'.$search.'%')
                ->orWhere('designation', 'like', '%'.$search.'%')))
            ->with(['asset', 'expectedRoom', 'expectedCondition', 'foundRoom', 'foundDepartment', 'checker'])
            ->orderByDesc('expected')
            ->orderBy('asset_id')
            ->paginate(25);
    }

    /**
     * @return Collection<int, InventoryLine>
     */
    public function getUnregistered(): Collection
    {
        return app(InventoryReport::class)->lines($this->inventory())
            ->where('result', InventoryLineResult::Unregistered)
            ->with(['foundRoom', 'foundCondition', 'checker'])
            ->orderBy('id')
            ->get();
    }

    /**
     * @return array<int, string>
     */
    public function getConditionOptions(): array
    {
        return AssetCondition::query()->active()->orderBy('sort')->pluck('label', 'id')->all();
    }

    /**
     * Locaux proposés : ceux du site inventorié (ou tous ceux du périmètre).
     *
     * @return array<int, string>
     */
    public function getRoomOptions(): array
    {
        $siteId = $this->inventory()->perimeter_site_id;

        return Room::query()->where('is_active', true)
            ->when($siteId, fn (Builder $query) => $query->where('site_id', $siteId))
            ->with('site')
            ->orderBy('name')
            ->get()
            ->mapWithKeys(fn (Room $room): array => [$room->id => $room->name.($siteId ? '' : ' · '.($room->site->name ?? '—'))])
            ->all();
    }

    public function assetUrl(InventoryLine $line): ?string
    {
        return $line->asset ? AssetResource::getUrl('view', ['record' => $line->asset]) : null;
    }

    public function otherAssignmentAction(): Action
    {
        return Action::make('otherAssignment')
            ->label('Autre affectation')
            ->color('warning')
            ->outlined()
            ->size('sm')
            ->modalHeading('Présent mais affecté ailleurs')
            ->modalDescription('Indiquez où le bien a été trouvé. La fiche n\'est pas modifiée : la régularisation se fera depuis le rapport, après la clôture (RG-48).')
            ->modalSubmitActionLabel('Enregistrer')
            ->fillForm(fn (array $arguments): array => ['room_id' => $this->roomId])
            ->schema([
                Select::make('department_id')
                    ->label('Département constaté')
                    ->placeholder('Inchangé')
                    ->options(fn (): array => Department::query()->withoutGlobalScopes()->where('is_active', true)->orderBy('name')->pluck('name', 'id')->all())
                    ->searchable(),
                Select::make('room_id')
                    ->label('Local constaté')
                    ->placeholder('Inchangé')
                    ->options(fn (): array => $this->getRoomOptions())
                    ->searchable(),
            ])
            ->action(function (array $data, array $arguments): void {
                $this->record((int) ($arguments['line'] ?? 0), 'other_assignment', $data, (int) ($arguments['version'] ?? -1));
            });
    }

    public function noteAction(): Action
    {
        return Action::make('note')
            ->label('Observation')
            ->icon('heroicon-o-chat-bubble-left-ellipsis')
            ->iconButton()
            ->color('gray')
            ->modalHeading('Observation sur ce bien')
            ->modalSubmitActionLabel('Enregistrer')
            ->fillForm(fn (array $arguments): array => [
                'notes' => InventoryLine::query()->where('inventory_id', $this->inventory()->id)->find($arguments['line'] ?? 0)?->notes,
            ])
            ->schema([
                Textarea::make('notes')->label('Observation')->rows(3)->maxLength(1000),
            ])
            ->action(function (array $data, array $arguments): void {
                $this->record((int) ($arguments['line'] ?? 0), 'note', [], (int) ($arguments['version'] ?? -1), (string) ($data['notes'] ?? ''));
            });
    }

    public function addUnregisteredAction(): Action
    {
        $inventory = $this->inventory();

        return Action::make('addUnregistered')
            ->label('Signaler un bien trouvé')
            ->icon('heroicon-o-plus')
            ->color('gray')
            ->modalHeading('Bien trouvé sans fiche (RG-46)')
            ->modalDescription('Il sera enregistré « non enregistré » ; sa fiche pourra être créée depuis le rapport.')
            ->modalSubmitActionLabel('Enregistrer')
            ->fillForm(fn (): array => [
                'site_id' => $inventory->perimeter_site_id ?? Room::query()->find($this->roomId)?->site_id,
                'department_id' => $inventory->perimeter_department_id,
                'room_id' => $this->roomId,
            ])
            ->schema([
                TextInput::make('description')->label('Description')->required()->maxLength(500)
                    ->placeholder('Ventilateur sur pied Binatone, sans étiquette'),
                Select::make('site_id')->label('Site')->required()->live()
                    ->options(fn (): array => Site::query()->where('is_active', true)->orderBy('name')->pluck('name', 'id')->all())
                    ->afterStateUpdated(fn (Set $set) => $set('room_id', null)),
                Select::make('department_id')->label('Département')->required()
                    ->options(fn (): array => Department::query()->where('is_active', true)->orderBy('name')->pluck('name', 'id')->all()),
                Select::make('room_id')->label('Local')->placeholder('Aucun')
                    ->options(fn (Get $get): array => Room::query()->where('is_active', true)->where('site_id', $get('site_id'))->orderBy('name')->pluck('name', 'id')->all()),
                Select::make('condition_id')->label('État constaté')->placeholder('Non renseigné')
                    ->options(fn (): array => $this->getConditionOptions()),
                Textarea::make('notes')->label('Observation')->rows(2),
            ])
            ->action(function (array $data, Action $action): void {
                try {
                    app(RecordInventoryEntry::class)->addUnregistered($this->inventory(), $this->user(), $data);
                    Notification::make()->success()->title('Bien non enregistré ajouté au rapport')->send();
                } catch (ValidationException $exception) {
                    Notification::make()->danger()->title('Saisie impossible')->body((string) collect($exception->errors())->flatten()->first())->send();
                    $action->halt();
                }
            });
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('checklist')
                ->label('Imprimer la liste de contrôle')
                ->icon('heroicon-o-printer')
                ->color('gray')
                ->action(fn (): Response => app(ExportInventoryReport::class)->checklist($this->inventory(), $this->user())),
            Action::make('submit')
                ->label('Terminer et voir le rapport')
                ->icon('heroicon-o-flag')
                ->authorize(fn (): bool => $this->user()->can('submit', $this->inventory()))
                ->requiresConfirmation()
                ->modalHeading('Terminer la saisie')
                ->modalDescription(fn (): string => ($pending = $this->getCounters()['pending']) > 0
                    ? $pending.' bien'.($pending > 1 ? 's restent' : ' reste').' à vérifier. Après la fin de la saisie, plus personne ne pourra pointer ; le gestionnaire pourra la rouvrir avant la clôture.'
                    : 'Tous les biens attendus ont un résultat. Après la fin de la saisie, plus personne ne pourra pointer.')
                ->modalSubmitActionLabel('Terminer la saisie')
                ->action(function (): void {
                    app(SubmitInventory::class)($this->inventory(), $this->user());
                    Notification::make()->success()->title('Saisie terminée : l\'inventaire est à valider')->send();
                    $this->redirect(InventoryResource::getUrl('report', ['record' => $this->inventory()]));
                }),
        ];
    }

    /**
     * @param  array<string, mixed>  $found
     */
    private function record(int $lineId, string $intent, array $found, int $version, ?string $notes = null): void
    {
        try {
            app(RecordInventoryEntry::class)->mark($this->inventory(), $this->user(), $lineId, $intent, $found, $version, $notes);
        } catch (InventoryLineConflictException $exception) {
            Notification::make()->warning()->title('Ligne pointée entre-temps')->body($exception->getMessage())->persistent()->send();
        } catch (ValidationException $exception) {
            Notification::make()->danger()->title('Saisie impossible')->body((string) collect($exception->errors())->flatten()->first())->send();
        }
    }

    private function user(): User
    {
        $user = auth()->user();
        abort_unless($user instanceof User, 403);

        return $user;
    }
}
