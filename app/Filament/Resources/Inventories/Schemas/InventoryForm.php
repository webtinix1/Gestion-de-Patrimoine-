<?php

namespace App\Filament\Resources\Inventories\Schemas;

use App\Enums\InventoryScopeType;
use App\Enums\PermissionAbility;
use App\Enums\PermissionModule;
use App\Models\AssetCategory;
use App\Models\Department;
use App\Models\Room;
use App\Models\Service;
use App\Models\Site;
use App\Models\User;
use App\Services\Inventories\InventoryScope;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\ToggleButtons;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Illuminate\Support\HtmlString;

/**
 * E19 : nouvel inventaire. Périmètre (RG-44) puis organisation. Les listes sont limitées au périmètre
 * de l'utilisateur (RG-81) ; l'action CreateInventory revérifie tout côté serveur.
 */
class InventoryForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Périmètre')
                ->columnSpanFull()
                ->columns(2)
                ->schema([
                    ToggleButtons::make('scope_type')
                        ->label('Type de périmètre')
                        ->options(InventoryScopeType::class)
                        ->default(InventoryScopeType::Site->value)
                        ->inline()
                        ->required()
                        ->live()
                        ->afterStateUpdated(function (Set $set): void {
                            $set('scope_id', null);
                            $set('site_id', null);
                        })
                        ->columnSpanFull(),
                    Select::make('scope_id')
                        ->label(fn (Get $get): string => self::type($get)?->valueLabel() ?: 'Élément')
                        ->options(fn (Get $get): array => self::scopeOptions(self::type($get)))
                        ->searchable()
                        ->required(fn (Get $get): bool => self::type($get)?->needsValue() ?? false)
                        ->visible(fn (Get $get): bool => self::type($get)?->needsValue() ?? false)
                        ->live(),
                    Select::make('site_id')
                        ->label('Limiter au site')
                        ->placeholder('Tous les sites')
                        ->options(fn (): array => Site::query()->where('is_active', true)->orderBy('name')->pluck('name', 'id')->all())
                        ->visible(fn (Get $get): bool => self::type($get)?->allowsSiteFilter() ?? false)
                        ->live(),
                    Text::make(fn (Get $get): HtmlString => self::expected($get))->columnSpanFull(),
                ]),
            Section::make('Organisation')
                ->columnSpanFull()
                ->columns(3)
                ->schema([
                    DatePicker::make('starts_on')->label('Date de début')->required()->default(today()),
                    DatePicker::make('ends_on')->label('Date de fin prévue')->afterOrEqual('starts_on'),
                    Select::make('manager_id')
                        ->label('Responsable de l\'inventaire')
                        ->options(fn (): array => self::eligibleUsers())
                        ->default(fn (): ?int => auth()->id())
                        ->searchable()
                        ->required(),
                    Repeater::make('participants')
                        ->label('Participants')
                        ->helperText('Seules les personnes désignées (et le gestionnaire du patrimoine) saisissent l\'inventaire. Le local confié est proposé par défaut à la saisie.')
                        ->addActionLabel('Ajouter un participant')
                        ->defaultItems(0)
                        ->columns(2)
                        ->columnSpanFull()
                        ->schema([
                            Select::make('user_id')
                                ->label('Participant')
                                ->options(fn (): array => self::eligibleUsers())
                                ->searchable()
                                ->required()
                                ->distinct(),
                            Select::make('room_id')
                                ->label('Local confié (facultatif)')
                                ->placeholder('Aucun')
                                ->options(fn (): array => Room::query()->where('is_active', true)->with('site')->orderBy('name')->get()
                                    ->mapWithKeys(fn (Room $room): array => [$room->id => $room->name.' · '.($room->site->name ?? '—')])->all())
                                ->searchable(),
                        ]),
                    Textarea::make('notes')->label('Observations')->rows(3)->columnSpanFull(),
                ]),
        ]);
    }

    private static function type(Get $get): ?InventoryScopeType
    {
        $type = $get('scope_type');

        return $type instanceof InventoryScopeType ? $type : InventoryScopeType::tryFrom((string) $type);
    }

    /**
     * @return array<int, string>
     */
    public static function scopeOptions(?InventoryScopeType $type): array
    {
        return match ($type) {
            InventoryScopeType::Site => Site::query()->where('is_active', true)->orderBy('name')->pluck('name', 'id')->all(),
            InventoryScopeType::Department => Department::query()->where('is_active', true)->orderBy('name')->pluck('name', 'id')->all(),
            InventoryScopeType::Service => Service::query()->where('is_active', true)->with('department')->orderBy('name')->get()
                ->mapWithKeys(fn (Service $service): array => [$service->id => $service->name.' · '.($service->department->name ?? '—')])->all(),
            InventoryScopeType::Category => AssetCategory::query()->where('is_active', true)->orderBy('name')->pluck('name', 'id')->all(),
            InventoryScopeType::Room => Room::query()->where('is_active', true)->with('site')->orderBy('name')->get()
                ->mapWithKeys(fn (Room $room): array => [$room->id => $room->name.' · '.($room->site->name ?? '—')])->all(),
            default => [],
        };
    }

    /**
     * Comptes actifs autorisés à saisir les inventaires (RG-82).
     *
     * @return array<int, string>
     */
    public static function eligibleUsers(): array
    {
        return User::query()
            ->where('is_active', true)
            ->permission(PermissionModule::Inventories->permission(PermissionAbility::Update))
            ->orderBy('last_name')
            ->get()
            ->mapWithKeys(fn (User $user): array => [$user->id => $user->fullName().($user->job_title ? ' · '.$user->job_title : '')])
            ->all();
    }

    private static function expected(Get $get): HtmlString
    {
        $type = self::type($get);
        $scopeId = filled($get('scope_id')) ? (int) $get('scope_id') : null;

        if ($type === null || ($type->needsValue() && $scopeId === null)) {
            return new HtmlString('<span style="color:#686868">Choisissez le périmètre pour voir le nombre de biens attendus.</span>');
        }

        $count = app(InventoryScope::class)->assets($type, $scopeId, filled($get('site_id')) ? (int) $get('site_id') : null)->count();

        return new HtmlString('<div style="display:flex;flex-wrap:wrap;gap:10px 24px;align-items:center;background:#F0F7F8;border:1px solid #C6DFE3;border-radius:8px;padding:12px 16px">'
            .'<span><strong>'.number_format($count, 0, ',', "\u{00A0}").'</strong> bien'.($count > 1 ? 's' : '').' attendu'.($count > 1 ? 's' : '').' à ce jour</span>'
            .'<span style="color:#686868">Liste figée au démarrage de l\'inventaire</span></div>');
    }
}
