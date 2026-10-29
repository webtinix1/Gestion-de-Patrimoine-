<?php

namespace App\Filament\Actions;

use App\Actions\Movements\TransferAssets;
use App\Exceptions\ConcurrentModificationException;
use App\Filament\Support\DocumentFields;
use App\Models\Asset;
use App\Models\Department;
use App\Models\DocumentType;
use App\Models\Holder;
use App\Models\Movement;
use App\Models\Room;
use App\Models\Service;
use App\Models\Site;
use Closure;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\HtmlString;
use Illuminate\Validation\ValidationException;

/**
 * E14 : action « Transférer », unitaire (fiche du bien) et groupée (liste des biens). Aucune règle ici :
 * tout passe par App\Actions\Movements\TransferAssets.
 */
final class TransferAction
{
    /** Q22 : motif en texte libre, avec ces suggestions. */
    public const array REASONS = [
        'Réaffectation entre départements',
        'Changement de responsable',
        'Déménagement',
        'Régularisation d\'inventaire',
    ];

    /** Éléments d'affectation, dans l'ordre d'affichage. */
    private const array ELEMENTS = ['site_id', 'department_id', 'service_id', 'room_id', 'holder_id'];

    public static function single(Asset $asset): Action
    {
        return self::configure(Action::make('transfer'))
            ->modalHeading('Transférer '.$asset->number)
            ->visible(! $asset->isDisposed())
            ->fillForm([
                ...collect(self::ELEMENTS)->mapWithKeys(fn (string $element): array => [$element => $asset->getAttribute($element)])->all(),
                'moved_on' => today()->toDateString(),
                'lock_version' => $asset->lock_version,
                'reprint_label' => true,
            ])
            ->schema([
                Hidden::make('lock_version'),
                Section::make('Affectation actuelle')->schema([
                    Text::make(fn (): HtmlString => self::currentAssignment($asset)),
                ]),
                ...self::fields($asset),
            ])
            ->action(function (array $data, Action $action) use ($asset): void {
                self::run([$asset], $data, [$asset->getKey() => (int) $data['lock_version']], $action);
            });
    }

    public static function bulk(): BulkAction
    {
        return self::configure(BulkAction::make('transfer'))
            ->modalHeading('Transférer les biens sélectionnés')
            ->deselectRecordsAfterCompletion()
            // RG-86 : versions des fiches à l'ouverture de la fenêtre, comparées à l'enregistrement.
            ->fillForm(fn (Collection $records): array => [
                'moved_on' => today()->toDateString(),
                'reprint_label' => true,
                'versions' => $records->mapWithKeys(fn (Asset $asset): array => [$asset->getKey() => (int) $asset->lock_version])->all(),
            ])
            ->schema([
                Hidden::make('versions'),
                Text::make('Tous les biens sélectionnés recevront cette nouvelle affectation : un mouvement est enregistré pour chaque bien.'),
                ...self::fields(null),
            ])
            ->action(function (array $data, Collection $records, BulkAction $action): void {
                $versions = [];

                foreach ((array) ($data['versions'] ?? []) as $id => $version) {
                    $versions[(int) $id] = (int) $version;
                }

                self::run($records->all(), $data, $versions, $action);
            });
    }

    /**
     * @template T of Action|BulkAction
     *
     * @param  T  $action
     * @return T
     */
    private static function configure(Action|BulkAction $action): Action|BulkAction
    {
        return $action
            ->label('Transférer')
            ->icon(Heroicon::OutlinedArrowsRightLeft)
            ->modalSubmitActionLabel('Enregistrer le transfert')
            ->modalWidth('3xl')
            ->authorize(fn (): bool => auth()->user()?->can('create', Movement::class) ?? false);
    }

    /**
     * @return list<Section>
     */
    private static function fields(?Asset $asset): array
    {
        // Champ modifié par rapport à l'affectation actuelle : mis en évidence (E14).
        $highlight = fn (string $element): Closure => fn (Get $get): array => $asset !== null && (int) $get($element) !== (int) $asset->getAttribute($element)
            ? ['style' => 'background:#F0F7F8;border-left:3px solid #0B5D6B;padding-left:8px']
            : [];

        return [
            Section::make('Nouvelle affectation')->columns(2)->schema([
                Select::make('site_id')
                    ->label('Site')
                    ->options(fn (): array => Site::query()->where('is_active', true)->orderBy('name')->pluck('name', 'id')->all())
                    ->required()
                    ->live()
                    ->afterStateUpdated(fn (Set $set) => $set('room_id', null))
                    ->extraFieldWrapperAttributes($highlight('site_id')),
                Select::make('department_id')
                    ->label('Département')
                    ->options(fn (): array => Department::query()->where('is_active', true)->orderBy('name')->pluck('name', 'id')->all())
                    ->required()
                    ->live()
                    ->afterStateUpdated(fn (Set $set) => $set('service_id', null))
                    ->extraFieldWrapperAttributes($highlight('department_id')),
                Select::make('service_id')
                    ->label('Service')
                    ->placeholder('Aucun')
                    ->options(fn (Get $get): array => Service::query()
                        ->where('is_active', true)->where('department_id', $get('department_id'))
                        ->orderBy('name')->pluck('name', 'id')->all())
                    ->live()
                    ->extraFieldWrapperAttributes($highlight('service_id')),
                Select::make('room_id')
                    ->label('Local')
                    ->placeholder('Aucun')
                    ->options(fn (Get $get): array => Room::query()
                        ->where('is_active', true)->where('site_id', $get('site_id'))
                        ->orderBy('name')->pluck('name', 'id')->all())
                    ->live()
                    ->extraFieldWrapperAttributes($highlight('room_id')),
                Select::make('holder_id')
                    ->label('Responsable')
                    ->placeholder('Aucun')
                    ->options(fn (): array => Holder::query()->inPerimeter()->where('is_active', true)->orderBy('name')->pluck('name', 'id')->all())
                    ->searchable()
                    ->live()
                    ->columnSpanFull()
                    ->extraFieldWrapperAttributes($highlight('holder_id')),
                Text::make(function (Get $get) use ($asset): string {
                    if ($asset === null) {
                        return 'Un élément laissé à « Aucun » est vidé pour tous les biens sélectionnés.';
                    }

                    $count = collect(self::ELEMENTS)->filter(fn (string $element): bool => (int) $get($element) !== (int) $asset->getAttribute($element))->count();

                    return ($count === 0 ? 'Aucun champ modifié' : $count.' champ'.($count > 1 ? 's' : '').' modifié'.($count > 1 ? 's' : '')).'. Le numéro '.$asset->number.' ne change pas.';
                })->columnSpanFull(),
            ]),
            Section::make('Détails du mouvement')->columns(2)->schema([
                DatePicker::make('moved_on')
                    ->label('Date du transfert')
                    ->required()
                    ->maxDate(today()),
                TextInput::make('reason')
                    ->label('Motif')
                    ->datalist(self::REASONS)
                    ->maxLength(255),
                Textarea::make('notes')
                    ->label('Observations')
                    ->rows(3)
                    ->columnSpanFull(),
                DocumentFields::files()
                    ->label('Justificatif (facultatif)')
                    ->required(false)
                    ->live()
                    ->columnSpanFull(),
                DocumentFields::type()
                    ->default(fn (): ?int => DocumentType::query()->active()->where('label', 'Document de transfert')->value('id'))
                    ->required(fn (Get $get): bool => filled($get('files')))
                    ->visible(fn (Get $get): bool => filled($get('files'))),
                Checkbox::make('reprint_label')
                    ->label('Réimprimer l\'étiquette (le site ou le département figure sur l\'étiquette)')
                    ->visible(fn (Get $get): bool => self::labelsPageExists() && ($asset === null
                        || (int) $get('site_id') !== (int) $asset->site_id
                        || (int) $get('department_id') !== (int) $asset->department_id))
                    ->columnSpanFull(),
            ]),
        ];
    }

    private static function currentAssignment(Asset $asset): HtmlString
    {
        $asset->loadMissing(['site', 'department', 'service', 'room', 'holder']);

        $rows = [
            'Site' => $asset->site->name, 'Département' => $asset->department->name, 'Service' => $asset->service?->name,
            'Local' => $asset->room?->name, 'Responsable' => $asset->holder?->name,
        ];

        return new HtmlString('<dl style="display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:8px 16px;margin:0">'
            .collect($rows)->map(fn (?string $value, string $label): string => '<div><dt style="font-size:12px;color:#686868">'.e($label).'</dt><dd style="margin:0;font-weight:600">'.e($value ?? '—').'</dd></div>')->join('')
            .'</dl>');
    }

    /**
     * @param  list<Asset>  $assets
     * @param  array<string, mixed>  $data
     * @param  array<int, int>  $versions
     */
    private static function run(array $assets, array $data, array $versions, Action|BulkAction $action): void
    {
        $typeId = $data['document_type_id'] ?? null;

        try {
            $movements = app(TransferAssets::class)(
                $assets,
                $data,
                $versions,
                $data['files'] ?? [],
                $typeId ? DocumentType::query()->active()->find($typeId) : null,
            );
        } catch (ValidationException $exception) {
            Notification::make()->danger()->title('Transfert impossible')->body(collect($exception->errors())->flatten()->first())->send();
            $action->halt();

            return;
        } catch (ConcurrentModificationException $exception) {
            Notification::make()->danger()->title('Fiche modifiée entre-temps')->body($exception->getMessage())->send();
            $action->halt();

            return;
        }

        Notification::make()->success()
            ->title($movements->count() > 1 ? $movements->count().' biens transférés' : 'Bien transféré')
            ->send();

        // RG-24 : le site ou le département figure sur l'étiquette, on propose la réimpression.
        $labelChanged = $movements->contains(fn (Movement $movement): bool => $movement->from_site_id !== $movement->to_site_id
            || $movement->from_department_id !== $movement->to_department_id);

        if (($data['reprint_label'] ?? false) && $labelChanged && self::labelsPageExists()) {
            $action->redirect(url('/admin/etiquettes').'?'.http_build_query(['biens' => $movements->pluck('asset_id')->join(',')]));
        }
    }

    /**
     * RG-24 : la page des étiquettes (lot 4) n'est proposée que si elle existe dans l'application.
     */
    public static function labelsPageExists(): bool
    {
        foreach (Route::getRoutes()->getRoutes() as $route) {
            if ($route->uri() === 'admin/etiquettes') {
                return true;
            }
        }

        return false;
    }
}
