<?php

namespace App\Filament\Actions;

use App\Actions\Reports\CreateAssetReport;
use App\Actions\Reports\ProcessAssetReport;
use App\Enums\ReportStatus;
use App\Enums\ReportType;
use App\Enums\ReportUrgency;
use App\Exceptions\ConcurrentModificationException;
use App\Filament\Resources\Maintenance\MaintenanceResource;
use App\Filament\Support\OperationFields;
use App\Models\Asset;
use App\Models\AssetReport;
use App\Models\Department;
use App\Models\Holder;
use App\Models\MaintenanceJob;
use App\Models\Movement;
use App\Models\Room;
use App\Models\Service;
use App\Models\Site;
use App\Support\DisposalUrl;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Support\Icons\Heroicon;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * E24, E25 : faire un signalement (responsable) et le traiter ou le rejeter (gestionnaire). Aucune règle ici :
 * tout passe par App\Actions\Reports.
 */
final class AssetReportActions
{
    private const array FAILURES = [ValidationException::class, ConcurrentModificationException::class, AuthorizationException::class];

    /**
     * RG-63 : sans bien imposé, le responsable choisit un bien de son périmètre.
     */
    public static function create(?Asset $asset = null): Action
    {
        return Action::make('report')
            ->label($asset === null ? 'Faire un signalement' : 'Signaler')
            ->icon(Heroicon::OutlinedFlag)
            ->color($asset === null ? 'primary' : 'gray')
            ->modalHeading('Signaler un problème sur un bien')
            ->modalSubmitActionLabel('Envoyer le signalement')
            ->modalWidth('3xl')
            ->visible(fn (): bool => (auth()->user()?->can('create', AssetReport::class) ?? false) && ($asset === null || ! $asset->isDisposed()))
            ->fillForm(fn (): array => ['asset_id' => $asset?->getKey(), 'urgency' => ReportUrgency::Normal->value, 'type' => ReportType::Maintenance->value])
            ->schema([
                OperationFields::assetSelect('asset_id', 'Bien concerné')
                    ->required()
                    ->live()
                    ->disabled($asset !== null)
                    ->dehydrated()
                    ->columnSpanFull(),
                Radio::make('type')
                    ->label('Nature du signalement')
                    ->options(ReportType::class)
                    ->live()
                    ->required(),
                OperationFields::conditions('reported_condition_id', 'État constaté')
                    ->visible(fn (Get $get): bool => OperationFields::value($get('type')) === ReportType::Condition->value)
                    ->required(fn (Get $get): bool => OperationFields::value($get('type')) === ReportType::Condition->value),
                Select::make('reported_room_id')
                    ->label('Où se trouve le bien maintenant ?')
                    ->options(fn (): array => Room::query()->where('is_active', true)->orderBy('name')->pluck('name', 'id')->all())
                    ->native(false)
                    ->visible(fn (Get $get): bool => OperationFields::value($get('type')) === ReportType::Move->value),
                Select::make('reported_department_id')
                    ->label('Utilisé par')
                    ->options(fn (): array => Department::query()->withoutGlobalScopes()->where('is_active', true)->orderBy('name')->pluck('name', 'id')->all())
                    ->native(false)
                    ->visible(fn (Get $get): bool => OperationFields::value($get('type')) === ReportType::Move->value),
                Select::make('urgency')
                    ->label('Urgence')
                    ->options(ReportUrgency::class)
                    ->native(false)
                    ->required(),
                Textarea::make('description')
                    ->label('Description')
                    ->rows(4)
                    ->maxLength(2000)
                    ->required()
                    ->columnSpanFull(),
                FileUpload::make('photo')
                    ->label('Photo (facultative)')
                    ->image()
                    ->storeFiles(false)
                    ->disk('local')
                    ->maxSize(5120)
                    ->helperText('Image de 5 Mo au plus.')
                    ->columnSpanFull(),
            ])
            ->action(function (array $data, Action $action): void {
                $photo = $data['photo'] ?? null;
                unset($data['photo']);

                try {
                    $report = app(CreateAssetReport::class)($data, $photo instanceof UploadedFile ? $photo : null);
                } catch (Throwable $exception) {
                    if (! self::isFailure($exception)) {
                        throw $exception;
                    }

                    OperationFields::notifyFailure('Signalement impossible', $exception);
                    $action->halt();

                    return;
                }

                Notification::make()->success()->title('Signalement '.$report->reference.' envoyé')
                    ->body('Le gestionnaire du patrimoine le reçoit. Vous suivez son statut dans « Signalements ».')
                    ->send();
            });
    }

    /**
     * RG-64 : actions du gestionnaire, chacune visible selon la nature et l'état du signalement.
     *
     * @return list<Action>
     */
    public static function processing(): array
    {
        return [
            self::takeCharge(),
            self::createMaintenance(),
            self::viewOperation(),
            self::transfer(),
            self::updateCondition(),
            self::proposeDisposal(),
            self::markHandled(),
            self::reject(),
        ];
    }

    private static function can(AssetReport $report): bool
    {
        return auth()->user()?->can('handle', $report) ?? false;
    }

    private static function takeCharge(): Action
    {
        return Action::make('takeCharge')
            ->label('Prendre en charge')
            ->icon(Heroicon::OutlinedHandRaised)
            ->color('gray')
            ->visible(fn (AssetReport $record): bool => self::can($record) && $record->status === ReportStatus::New)
            ->action(fn (AssetReport $record, Action $action) => self::run($action, 'Prise en charge impossible', function () use ($record): void {
                app(ProcessAssetReport::class)->takeCharge($record);
                Notification::make()->success()->title('Signalement pris en charge')->send();
            }));
    }

    private static function createMaintenance(): Action
    {
        return Action::make('createMaintenance')
            ->label('Créer une intervention')
            ->icon(Heroicon::OutlinedWrenchScrewdriver)
            ->visible(fn (AssetReport $record): bool => self::can($record)
                && $record->handled_ref_id === null
                && in_array($record->type, [ReportType::Condition, ReportType::Maintenance], true)
                && (auth()->user()?->can('create', MaintenanceJob::class) ?? false))
            ->url(fn (AssetReport $record): string => MaintenanceResource::getUrl('create').'?'.http_build_query(['bien' => $record->asset_id, 'signalement' => $record->getKey()]));
    }

    private static function viewOperation(): Action
    {
        return Action::make('viewOperation')
            ->label('Voir l\'intervention')
            ->icon(Heroicon::OutlinedEye)
            ->color('gray')
            ->visible(fn (AssetReport $record): bool => $record->handledRef instanceof MaintenanceJob)
            ->url(fn (AssetReport $record): ?string => $record->handledRef instanceof MaintenanceJob ? MaintenanceResource::getUrl('view', ['record' => $record->handledRef]) : null);
    }

    private static function transfer(): Action
    {
        return Action::make('transfer')
            ->label('Enregistrer le transfert')
            ->icon(Heroicon::OutlinedArrowsRightLeft)
            ->modalHeading(fn (AssetReport $record): string => 'Transférer le bien du signalement '.$record->reference)
            ->modalSubmitActionLabel('Enregistrer le transfert')
            ->modalWidth('3xl')
            ->visible(fn (AssetReport $record): bool => self::can($record) && $record->type === ReportType::Move && (auth()->user()?->can('create', Movement::class) ?? false))
            ->fillForm(function (AssetReport $record): array {
                $asset = $record->asset;
                $departmentId = $record->reported_department_id ?? $asset?->department_id;
                $roomId = $record->reported_room_id ?? $asset?->room_id;
                $room = $roomId === null ? null : Room::query()->find($roomId);

                return [
                    'site_id' => $room->site_id ?? $asset->site_id,
                    'department_id' => $departmentId,
                    'service_id' => $departmentId === $asset?->department_id ? $asset?->service_id : null,
                    'room_id' => $room?->getKey(),
                    'holder_id' => $asset?->holder_id,
                    'moved_on' => today()->toDateString(),
                    'reason' => 'Signalement '.$record->reference,
                    'asset_version' => $asset?->lock_version,
                ];
            })
            ->schema([
                Hidden::make('asset_version'),
                Select::make('site_id')->label('Site')->options(fn (): array => Site::query()->where('is_active', true)->orderBy('name')->pluck('name', 'id')->all())->native(false)->required(),
                Select::make('department_id')->label('Département')->options(fn (): array => Department::query()->where('is_active', true)->orderBy('name')->pluck('name', 'id')->all())->native(false)->required(),
                Select::make('service_id')->label('Service')->placeholder('Aucun')->options(fn (Get $get): array => Service::query()->where('is_active', true)->where('department_id', $get('department_id'))->orderBy('name')->pluck('name', 'id')->all())->native(false),
                Select::make('room_id')->label('Local')->placeholder('Aucun')->options(fn (Get $get): array => Room::query()->where('is_active', true)->where('site_id', $get('site_id'))->orderBy('name')->pluck('name', 'id')->all())->native(false),
                Select::make('holder_id')->label('Responsable')->placeholder('Aucun')->options(fn (): array => Holder::query()->where('is_active', true)->orderBy('name')->pluck('name', 'id')->all())->searchable()->native(false),
                DatePicker::make('moved_on')->label('Date du transfert')->maxDate(today())->required(),
                TextInput::make('reason')->label('Motif')->maxLength(255)->columnSpanFull(),
            ])
            ->action(fn (array $data, AssetReport $record, Action $action) => self::run($action, 'Transfert impossible', function () use ($data, $record): void {
                $version = filled($data['asset_version'] ?? null) ? (int) $data['asset_version'] : null;
                unset($data['asset_version']);

                app(ProcessAssetReport::class)->transfer($record, $data, $version);
                Notification::make()->success()->title('Transfert enregistré, signalement traité')->send();
            }));
    }

    private static function updateCondition(): Action
    {
        return Action::make('updateCondition')
            ->label('Mettre à jour l\'état')
            ->icon(Heroicon::OutlinedCheckCircle)
            ->modalSubmitActionLabel('Mettre à jour l\'état du bien')
            ->visible(fn (AssetReport $record): bool => self::can($record) && $record->type === ReportType::Condition && (auth()->user()?->can('update', $record->asset ?? new Asset) ?? false))
            ->fillForm(fn (AssetReport $record): array => ['condition_id' => $record->reported_condition_id, 'asset_version' => $record->asset?->lock_version])
            ->schema([
                Hidden::make('asset_version'),
                OperationFields::conditions('condition_id', 'Nouvel état du bien')->required(),
            ])
            ->action(fn (array $data, AssetReport $record, Action $action) => self::run($action, 'Mise à jour impossible', function () use ($data, $record): void {
                app(ProcessAssetReport::class)->updateCondition($record, (int) ($data['condition_id'] ?? 0), filled($data['asset_version'] ?? null) ? (int) $data['asset_version'] : null);
                Notification::make()->success()->title('État mis à jour, signalement traité')->send();
            }));
    }

    // RG-61, RG-64 : contrat avec la page des sorties (lot 10), proposé seulement si elle existe.
    private static function proposeDisposal(): Action
    {
        return Action::make('proposeDisposal')
            ->label('Proposer la sortie')
            ->icon(Heroicon::OutlinedArchiveBoxArrowDown)
            ->color('danger')
            ->visible(fn (AssetReport $record): bool => self::can($record)
                && $record->type === ReportType::Condition
                && DisposalUrl::pageExists()
                && $record->asset !== null
                && ! $record->asset->isDisposed())
            ->url(fn (AssetReport $record): string => DisposalUrl::for($record->asset_id));
    }

    private static function markHandled(): Action
    {
        return Action::make('markHandled')
            ->label('Marquer comme traité')
            ->icon(Heroicon::OutlinedCheckCircle)
            ->color('gray')
            ->requiresConfirmation()
            ->modalDescription('À utiliser quand l\'opération a été faite hors de cet écran (sortie du patrimoine, par exemple).')
            ->visible(fn (AssetReport $record): bool => self::can($record) && $record->status === ReportStatus::InProgress && $record->handled_ref_id === null)
            ->action(fn (AssetReport $record, Action $action) => self::run($action, 'Traitement impossible', function () use ($record): void {
                app(ProcessAssetReport::class)->markHandled($record);
                Notification::make()->success()->title('Signalement traité')->send();
            }));
    }

    private static function reject(): Action
    {
        return Action::make('reject')
            ->label('Rejeter')
            ->icon(Heroicon::OutlinedXCircle)
            ->color('danger')
            ->modalSubmitActionLabel('Rejeter le signalement')
            ->visible(fn (AssetReport $record): bool => self::can($record))
            ->schema([
                Textarea::make('rejection_reason')->label('Motif du rejet')->rows(3)->maxLength(2000)->required(),
            ])
            ->action(fn (array $data, AssetReport $record, Action $action) => self::run($action, 'Rejet impossible', function () use ($data, $record): void {
                app(ProcessAssetReport::class)->reject($record, $data['rejection_reason'] ?? null);
                Notification::make()->success()->title('Signalement rejeté')->send();
            }));
    }

    private static function run(Action $action, string $title, callable $callback): void
    {
        try {
            $callback();
        } catch (Throwable $exception) {
            if (! self::isFailure($exception)) {
                throw $exception;
            }

            OperationFields::notifyFailure($title, $exception);
            $action->halt();
        }
    }

    private static function isFailure(Throwable $exception): bool
    {
        foreach (self::FAILURES as $class) {
            if ($exception instanceof $class) {
                return true;
            }
        }

        return false;
    }
}
