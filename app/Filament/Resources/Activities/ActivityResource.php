<?php

namespace App\Filament\Resources\Activities;

use App\Filament\Resources\Activities\Pages\ListActivities;
use App\Models\Activity;
use App\Models\Asset;
use App\Models\Role;
use App\Models\User;
use BackedEnum;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
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
        'documents' => 'Documents',
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

        $assetType = (new Asset)->getMorphClass();

        return $query->where(fn (Builder $query) => $query
            ->where(fn (Builder $query) => $query->whereNull('subject_type')->orWhere('subject_type', '!=', $assetType))
            ->orWhereHasMorph('subject', [Asset::class]));
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
            default => class_basename($subject).' n° '.$subject->getKey(),
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
                .(array_key_exists($key, $old) ? '<del class="text-danger-700">'.e(self::stringify($old[$key])).'</del> → ' : '')
                .'<ins class="text-success-700 no-underline">'.e(self::stringify($new[$key] ?? null)).'</ins></div>';
        }

        if ($limit !== null && count($keys) > $limit) {
            $lines[] = '<div class="text-gray-500">… '.(count($keys) - $limit).' autre(s)</div>';
        }

        return new HtmlString(implode('', $lines));
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
