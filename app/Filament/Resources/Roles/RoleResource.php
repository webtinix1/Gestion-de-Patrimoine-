<?php

namespace App\Filament\Resources\Roles;

use App\Enums\PermissionAbility;
use App\Enums\PermissionModule;
use App\Filament\Resources\Roles\Pages\CreateRole;
use App\Filament\Resources\Roles\Pages\EditRole;
use App\Filament\Resources\Roles\Pages\ListRoles;
use App\Models\Role;
use BackedEnum;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Callout;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use UnitEnum;

/**
 * E39 · RG-80 : matrice module × droit, paramétrable par l'administrateur.
 */
class RoleResource extends Resource
{
    protected static ?string $model = Role::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedShieldCheck;

    protected static string|UnitEnum|null $navigationGroup = 'Administration';

    protected static ?int $navigationSort = 2;

    protected static bool $hasTitleCaseModelLabel = false;

    protected static ?string $modelLabel = 'rôle';

    protected static ?string $pluralModelLabel = 'Rôles et permissions';

    protected static ?string $navigationLabel = 'Rôles et permissions';

    protected static ?string $slug = 'roles';

    protected static ?string $recordTitleAttribute = 'name';

    public static function form(Schema $schema): Schema
    {
        $isLocked = fn (?Role $record): bool => $record?->isLocked() ?? false;

        return $schema
            ->components([
                Section::make('Rôle')
                    ->columnSpanFull()
                    ->schema([
                        TextInput::make('name')
                            ->label('Nom du rôle')
                            ->required()
                            ->maxLength(100)
                            ->unique(ignoreRecord: true)
                            ->disabled(fn (?Role $record): bool => $record?->isDefault() ?? false)
                            ->helperText(fn (?Role $record): ?string => $record?->description()),
                    ]),
                Section::make('Droits par module')
                    ->description('Le périmètre (sites, départements) se règle sur chaque utilisateur.')
                    ->columnSpanFull()
                    ->schema([
                        Callout::make('Le rôle Administrateur garde tous les droits.')
                            ->info()
                            ->visible($isLocked),
                        ...array_map(
                            fn (PermissionModule $module): CheckboxList => CheckboxList::make(self::field($module))
                                ->label($module->getLabel())
                                ->options(collect(PermissionAbility::cases())
                                    ->mapWithKeys(fn (PermissionAbility $ability): array => [$module->permission($ability) => $ability->getLabel()])
                                    ->all())
                                ->columns(6)
                                ->bulkToggleable()
                                ->disabled($isLocked),
                            PermissionModule::cases(),
                        ),
                    ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->withCount(['users', 'permissions']))
            ->defaultSort('id')
            ->columns([
                TextColumn::make('name')
                    ->label('Rôle')
                    ->weight('semibold')
                    ->description(fn (Role $record): ?string => $record->description())
                    ->searchable(),
                TextColumn::make('users_count')
                    ->label('Utilisateurs')
                    ->numeric(),
                TextColumn::make('permissions_count')
                    ->label('Droits accordés')
                    ->numeric(),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make(),
            ]);
    }

    public static function field(PermissionModule $module): string
    {
        return 'permissions_'.$module->value;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return list<string>
     */
    public static function permissionsFromData(array $data): array
    {
        $permissions = [];

        foreach (PermissionModule::cases() as $module) {
            foreach ($data[self::field($module)] ?? [] as $permission) {
                $permissions[] = (string) $permission;
            }
        }

        return $permissions;
    }

    public static function getPages(): array
    {
        return [
            'index' => ListRoles::route('/'),
            'create' => CreateRole::route('/create'),
            'edit' => EditRole::route('/{record}/edit'),
        ];
    }
}
