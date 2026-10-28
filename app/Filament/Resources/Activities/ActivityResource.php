<?php

namespace App\Filament\Resources\Activities;

use App\Enums\AssetStatus;
use App\Enums\DepreciationMethod;
use App\Enums\FundingSource;
use App\Filament\Resources\Activities\Pages\ListActivities;
use App\Models\Activity;
use App\Models\Asset;
use App\Models\AssetCategory;
use App\Models\AssetCondition;
use App\Models\Department;
use App\Models\Holder;
use App\Models\Inventory;
use App\Models\Loan;
use App\Models\Role;
use App\Models\Room;
use App\Models\Service;
use App\Models\Site;
use App\Models\Supplier;
use App\Models\User;
use App\Services\Inventories\InventoryScope;
use App\Support\OperationLabels;
use BackedEnum;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Contracts\HasLabel;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\HtmlString;
use UnitEnum;

/**
 * E40 · RG-70 à RG-72 : journal des opérations, en lecture seule.
 */
class ActivityResource extends Resource
{
    protected static ?string $model = Activity::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClipboardDocumentList;

    protected static string|UnitEnum|null $navigationGroup = 'Administration';

    protected static ?int $navigationSort = 3;

    protected static bool $hasTitleCaseModelLabel = false;

    protected static ?string $modelLabel = 'opération';

    protected static ?string $pluralModelLabel = 'Journal des opérations';

    protected static ?string $navigationLabel = 'Journal des opérations';

    protected static ?string $slug = 'journal';

    /**
     * @var array<string, string>
     */
    public const array LOG_NAMES = [
        'connexion' => 'Connexion',
        'utilisateurs' => 'Utilisateurs',
        'roles' => 'Rôles et permissions',
        'biens' => 'Biens',
        'mouvements' => 'Mouvements',
        'prets' => 'Prêts et emprunts',
        'maintenance' => 'Maintenance',
        'signalements' => 'Signalements',
        'documents' => 'Documents',
        'amortissements' => 'Immobilisations et amortissements',
        'etiquettes' => 'Étiquettes',
        'inventaires' => 'Inventaires',
        'sorties' => 'Sorties du patrimoine',
        'parametres' => 'Paramètres',
    ];

    public static function canCreate(): bool
    {
        return false;
    }

    /**
     * RG-81 : une opération portant sur un bien n'apparaît que si ce bien est dans le
     * périmètre de l'utilisateur (le scope global s'applique dans whereHasMorph).
     *
     * @return Builder<Activity>
     */
    public static function getEloquentQuery(): Builder
    {
        $query = Activity::query();
        $user = auth()->user();

        if (! $user instanceof User || ($user->perimeterSiteIds() === null && $user->perimeterDepartmentIds() === null)) {
            return $query;
        }

        $inventoryType = (new Inventory)->getMorphClass();
        // QL8-1 : un emprunt (sans bien) n'est visible que d'un utilisateur sans périmètre restreint.
        $types = [(new Asset)->getMorphClass(), (new Loan)->getMorphClass(), $inventoryType];

        return $query->where(fn (Builder $query) => $query
            ->where(fn (Builder $query) => $query->whereNull('subject_type')->orWhereNotIn('subject_type', $types))
            ->orWhereHasMorph('subject', [Asset::class, Loan::class])
            // Entrée portant sur un inventaire : visible si le périmètre contient l'inventaire, ou si le
            // bien non enregistré qu'elle trace est dans le périmètre.
            ->orWhere(fn (Builder $query) => $query->where('subject_type', $inventoryType)
                ->where(fn (Builder $query) => app(InventoryScope::class)->whereFoundWithin(
                    $query->whereIn('subject_id', app(InventoryScope::class)->containedIn($user)->select('id')),
                    $user,
                ))));
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('id', 'desc')
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['causer', 'subject']))
            ->columns([
                TextColumn::make('created_at')
                    ->label('Date et heure')
                    ->dateTime('d/m/Y H:i:s')
                    ->fontFamily('mono')
                    ->sortable(),
                TextColumn::make('causer_id')
                    ->label('Utilisateur')
                    ->formatStateUsing(fn (Activity $record): string => self::causerName($record))
                    ->placeholder('Système'),
                TextColumn::make('description')
                    ->label('Opération')
                    ->badge()
                    ->color(fn (Activity $record): string => match ($record->log_name) {
                        'connexion' => $record->event === 'locked' ? 'danger' : 'gray',
                        'roles' => 'warning',
                        default => 'primary',
                    })
                    ->searchable(),
                TextColumn::make('subject_id')
                    ->label('Objet')
                    ->formatStateUsing(fn (Activity $record): string => self::subjectLabel($record))
                    ->placeholder('—'),
                TextColumn::make('changes')
                    ->label('Avant / après')
                    ->state(fn (Activity $record): HtmlString => self::changesHtml($record, 3))
                    ->html()
                    ->wrap(),
                TextColumn::make('ip')
                    ->label('Poste')
                    ->state(fn (Activity $record): ?string => $record->ip())
                    ->fontFamily('mono')
                    ->placeholder('—'),
            ])
            ->filters([
                SelectFilter::make('causer_id')
                    ->label('Utilisateur')
                    ->options(fn (): array => User::query()->orderBy('last_name')->get()
                        ->mapWithKeys(fn (User $user): array => [$user->id => $user->fullName()])
                        ->all())
                    ->query(fn (Builder $query, array $data) => $query->when(
                        $data['value'] ?? null,
                        fn (Builder $query, $id) => $query->where('causer_type', (new User)->getMorphClass())->where('causer_id', $id),
                    )),
                SelectFilter::make('log_name')
                    ->label('Domaine')
                    ->options(self::LOG_NAMES),
                Filter::make('period')
                    ->schema([
                        DatePicker::make('from')->label('Du'),
                        DatePicker::make('until')->label('Au'),
                    ])
                    ->columns(2)
                    ->query(fn (Builder $query, array $data) => $query
                        ->when($data['from'] ?? null, fn (Builder $query, $date) => $query->whereDate('created_at', '>=', $date))
                        ->when($data['until'] ?? null, fn (Builder $query, $date) => $query->whereDate('created_at', '<=', $date))),
            ])
            ->recordActions([
                ViewAction::make(),
            ]);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make()
                    ->columns(2)
                    ->columnSpanFull()
                    ->schema([
                        TextEntry::make('created_at')->label('Date et heure')->dateTime('d/m/Y H:i:s'),
                        TextEntry::make('causer_id')->label('Utilisateur')
                            ->formatStateUsing(fn (Activity $record): string => self::causerName($record))
                            ->placeholder('Système'),
                        TextEntry::make('description')->label('Opération'),
                        TextEntry::make('subject_id')->label('Objet')
                            ->formatStateUsing(fn (Activity $record): string => self::subjectLabel($record))
                            ->placeholder('—'),
                        TextEntry::make('ip')->label('Poste (adresse IP)')
                            ->state(fn (Activity $record): ?string => $record->ip())
                            ->placeholder('—'),
                        TextEntry::make('changes')->label('Valeurs avant et après')
                            ->state(fn (Activity $record): HtmlString => self::changesHtml($record))
                            ->html()
                            ->columnSpanFull(),
                    ]),
            ]);
    }

    public static function causerName(Activity $activity): string
    {
        $causer = $activity->causer;

        return $causer instanceof User ? $causer->fullName() : 'Système';
    }

    public static function subjectLabel(Activity $activity): string
    {
        $subject = $activity->subject;

        if (! $subject instanceof Model) {
            return $activity->subject_type ? class_basename($activity->subject_type).' n° '.$activity->subject_id : '—';
        }

        return match (true) {
            $subject instanceof User => 'Utilisateur '.$subject->username,
            $subject instanceof Role => 'Rôle '.$subject->name,
            $subject instanceof Inventory => 'Inventaire '.$subject->reference,
            default => OperationLabels::for($subject),
        };
    }

    /**
     * RG-71 : valeurs avant et après.
     */
    public static function changesHtml(Activity $activity, ?int $limit = null): HtmlString
    {
        $new = (array) $activity->properties->get('attributes', []);
        $old = (array) $activity->properties->get('old', []);
        $keys = array_values(array_unique([...array_keys($old), ...array_keys($new)]));

        if ($keys === []) {
            return new HtmlString('<span class="text-gray-500">—</span>');
        }

        $lines = [];

        foreach (array_slice($keys, 0, $limit ?? count($keys)) as $key) {
            $lines[] = '<div><span class="text-gray-600">'.e(self::attributeLabel($key)).' :</span> '
                .(array_key_exists($key, $old) ? '<del class="text-danger-700">'.e(self::display($activity, $key, $old[$key])).'</del> → ' : '')
                .'<ins class="text-success-700 no-underline">'.e(self::display($activity, $key, $new[$key] ?? null)).'</ins></div>';
        }

        if ($limit !== null && count($keys) > $limit) {
            $lines[] = '<div class="text-gray-500">… '.(count($keys) - $limit).' autre(s)</div>';
        }

        return new HtmlString(implode('', $lines));
    }

    /**
     * Modèles liés par une clé étrangère du bien : le journal affiche leur nom, jamais leur identifiant.
     *
     * @var array<string, array{class-string<Model>, string}>
     */
    private const array RELATIONS = [
        'site_id' => [Site::class, 'name'],
        'department_id' => [Department::class, 'name'],
        'service_id' => [Service::class, 'name'],
        'room_id' => [Room::class, 'name'],
        'holder_id' => [Holder::class, 'name'],
        'asset_category_id' => [AssetCategory::class, 'name'],
        'asset_condition_id' => [AssetCondition::class, 'label'],
        'supplier_id' => [Supplier::class, 'name'],
    ];

    /**
     * Énumérations d'une fiche de bien, rendues par leur libellé.
     *
     * @var array<string, class-string<BackedEnum&HasLabel>>
     */
    private const array ASSET_ENUMS = [
        'status' => AssetStatus::class,
        'funding_source' => FundingSource::class,
        'depreciation_method' => DepreciationMethod::class,
    ];

    /**
     * Valeur lisible d'une entrée du journal : nom de l'objet lié, libellé d'énumération, date locale en jj/mm/aaaa.
     */
    public static function display(Activity $activity, string $key, mixed $value): string
    {
        if ((is_int($value) || (is_string($value) && ctype_digit($value))) && isset(self::RELATIONS[$key])) {
            [$class, $column] = self::RELATIONS[$key];
            // Le journal garde les noms réels, quel que soit le périmètre de l'utilisateur : pas de scope global.
            $name = (new $class)->newQuery()->withoutGlobalScopes()->whereKey((int) $value)->value($column);

            return filled($name) ? (string) $name : 'n° '.$value;
        }

        if (is_string($value) && isset(self::ASSET_ENUMS[$key]) && $activity->subject_type === (new Asset)->getMorphClass()) {
            $label = self::ASSET_ENUMS[$key]::tryFrom($value)?->getLabel();

            if ($label !== null) {
                return (string) $label;
            }
        }

        if (is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}(T\d{2}:\d{2}:\d{2}(\.\d+)?(Z|[+-]\d{2}:\d{2}))?$/', $value) === 1) {
            $date = Carbon::parse($value)->timezone((string) config('app.timezone'));

            return $date->format(strlen($value) > 10 && $date->format('H:i') !== '00:00' ? 'd/m/Y H:i' : 'd/m/Y');
        }

        return self::stringify($value);
    }

    private static function attributeLabel(string $key): string
    {
        return __('validation.attributes.'.$key) !== 'validation.attributes.'.$key
            ? (string) __('validation.attributes.'.$key)
            : str_replace('_', ' ', $key);
    }

    private static function stringify(mixed $value): string
    {
        return match (true) {
            $value === null || $value === '' => '—',
            is_bool($value) => $value ? 'oui' : 'non',
            is_array($value) => $value === [] ? '—' : implode(', ', array_map(fn ($item) => self::stringify($item), $value)),
            is_scalar($value) => (string) $value,
            default => (string) json_encode($value),
        };
    }

    public static function getPages(): array
    {
        return [
            'index' => ListActivities::route('/'),
        ];
    }
}
