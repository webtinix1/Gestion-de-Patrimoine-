<?php

namespace App\Filament\Actions;

use App\Actions\Maintenance\CloseMaintenanceJob;
use App\Actions\Maintenance\StartMaintenanceJob;
use App\Enums\AssetStatus;
use App\Enums\MaintenanceResult;
use App\Enums\MaintenanceStatus;
use App\Exceptions\ConcurrentModificationException;
use App\Filament\Support\OperationFields;
use App\Models\DocumentType;
use App\Models\MaintenanceJob;
use App\Support\DisposalUrl;
use Filament\Actions\Action;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Support\Icons\Heroicon;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;

/**
 * E23 : démarrer et clôturer une intervention (RG-61). Aucune règle ici : tout passe par App\Actions\Maintenance.
 */
final class MaintenanceJobActions
{
    public static function start(): Action
    {
        return Action::make('start')
            ->label('Démarrer l\'intervention')
            ->icon(Heroicon::OutlinedPlay)
            ->modalSubmitActionLabel('Démarrer')
            ->visible(fn (MaintenanceJob $record): bool => $record->status === MaintenanceStatus::Planned && (auth()->user()?->can('update', $record) ?? false))
            ->fillForm(fn (MaintenanceJob $record): array => [
                'put_in_maintenance' => true,
                'asset_version' => $record->asset?->lock_version,
            ])
            ->schema([
                Hidden::make('asset_version'),
                Checkbox::make('put_in_maintenance')->label('Passer le bien au statut « En maintenance » pendant l\'intervention'),
            ])
            ->action(function (array $data, MaintenanceJob $record, Action $action): void {
                try {
                    app(StartMaintenanceJob::class)($record, (bool) ($data['put_in_maintenance'] ?? false), filled($data['asset_version'] ?? null) ? (int) $data['asset_version'] : null);
                } catch (ValidationException|ConcurrentModificationException|AuthorizationException $exception) {
                    OperationFields::notifyFailure('Démarrage impossible', $exception);
                    $action->halt();

                    return;
                }

                Notification::make()->success()->title('Intervention démarrée')->send();
            });
    }

    public static function close(): Action
    {
        return Action::make('close')
            ->label('Clôturer l\'intervention')
            ->icon(Heroicon::OutlinedCheckCircle)
            ->modalHeading(fn (MaintenanceJob $record): string => 'Clôturer '.$record->reference)
            ->modalSubmitActionLabel('Clôturer l\'intervention')
            ->modalWidth('3xl')
            ->visible(fn (MaintenanceJob $record): bool => $record->status === MaintenanceStatus::Ongoing && (auth()->user()?->can('update', $record) ?? false))
            ->fillForm(fn (MaintenanceJob $record): array => [
                'ends_on' => today()->toDateString(),
                'condition_after_id' => $record->asset?->asset_condition_id,
                'status_after' => AssetStatus::InService->value,
                'cost' => $record->cost,
                'cost_reference' => $record->cost_reference,
                'asset_version' => $record->asset?->lock_version,
                'status_applies' => $record->asset !== null
                    && ($record->asset->status === AssetStatus::InMaintenance || $record->asset->status->isManuallySelectable()),
            ])
            ->schema([
                Hidden::make('asset_version'),
                Hidden::make('status_applies'),
                Select::make('result')
                    ->label('Résultat')
                    ->options(MaintenanceResult::class)
                    ->native(false)
                    ->live()
                    ->required()
                    ->helperText('Si irréparable, une sortie du patrimoine pourra être proposée.'),
                OperationFields::conditions('condition_after_id', 'Nouvel état du bien')->required(),
                Select::make('status_after')
                    ->label('Statut après intervention')
                    ->options(collect(AssetStatus::cases())->filter(fn (AssetStatus $status): bool => $status->isManuallySelectable())
                        ->mapWithKeys(fn (AssetStatus $status): array => [$status->value => $status->getLabel()])->all())
                    ->native(false)
                    ->visible(fn (Get $get): bool => (bool) $get('status_applies'))
                    ->required(fn (Get $get): bool => (bool) $get('status_applies')),
                DatePicker::make('ends_on')->label('Date de fin')->maxDate(today())->required(),
                TextInput::make('cost')->label('Coût final')->numeric()->minValue(0)->suffix('FCFA'),
                TextInput::make('cost_reference')->label('Référence devis ou facture')->maxLength(255),
                Textarea::make('notes')->label('Observations')->rows(3)->columnSpanFull(),
                ...OperationFields::documents('Joindre la facture ou le rapport (facultatif)', 'Justificatif de maintenance'),
            ])
            ->action(function (array $data, MaintenanceJob $record, Action $action): void {
                $typeId = $data['document_type_id'] ?? null;

                try {
                    $job = app(CloseMaintenanceJob::class)(
                        $record,
                        $data,
                        filled($data['asset_version'] ?? null) ? (int) $data['asset_version'] : null,
                        $data['files'] ?? [],
                        $typeId ? DocumentType::query()->active()->find($typeId) : null,
                    );
                } catch (ValidationException|ConcurrentModificationException|AuthorizationException $exception) {
                    OperationFields::notifyFailure('Clôture impossible', $exception);
                    $action->halt();

                    return;
                }

                $notification = Notification::make()->success()->title('Intervention clôturée');

                // RG-61 : un résultat « irréparable » propose une sortie, si la page des sorties existe.
                if ($job->result === MaintenanceResult::Irreparable && DisposalUrl::pageExists() && $job->asset !== null && ! $job->asset->isDisposed()) {
                    $notification
                        ->body('Le bien est déclaré irréparable : vous pouvez proposer sa sortie du patrimoine.')
                        ->actions([Action::make('proposeDisposal')->label('Proposer la sortie')->url(DisposalUrl::for($job->asset_id))]);
                }

                $notification->send();
            });
    }
}
