<?php

namespace App\Filament\Actions;

use App\Actions\Loans\RecordLoanReturn;
use App\Enums\LoanDirection;
use App\Exceptions\ConcurrentModificationException;
use App\Filament\Resources\Maintenance\MaintenanceResource;
use App\Filament\Support\OperationFields;
use App\Models\AssetCondition;
use App\Models\DocumentType;
use App\Models\Loan;
use App\Models\LoanItem;
use App\Models\MaintenanceJob;
use Filament\Actions\Action;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Support\Icons\Heroicon;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;

/**
 * E17 : retour d'un prêt (ou restitution d'un emprunt), état par bien, alerte si dégradé et proposition
 * d'intervention (RG-56, RG-57). Aucune règle ici : tout passe par App\Actions\Loans\RecordLoanReturn.
 */
final class LoanReturnAction
{
    public static function make(): Action
    {
        return Action::make('return')
            ->label(fn (Loan $record): string => $record->direction === LoanDirection::In ? 'Restituer' : 'Enregistrer le retour')
            ->icon(Heroicon::OutlinedArrowUturnLeft)
            ->modalHeading(fn (Loan $record): string => ($record->direction === LoanDirection::In ? 'Restitution de ' : 'Retour du prêt ').$record->reference)
            ->modalSubmitActionLabel('Valider le retour')
            ->modalWidth('3xl')
            ->visible(fn (Loan $record): bool => auth()->user()?->can('update', $record) ?? false)
            ->fillForm(function (Loan $record): array {
                $record->loadMissing(['items.asset', 'items.conditionOut']);

                return [
                    'returned_on' => today()->toDateString(),
                    'open_maintenance' => true,
                    'is_out' => $record->direction === LoanDirection::Out,
                    // RG-86 : versions des fiches à l'ouverture de la fenêtre, comparées à l'enregistrement.
                    'versions' => $record->items->filter(fn (LoanItem $item): bool => $item->asset !== null)
                        ->mapWithKeys(fn (LoanItem $item): array => [$item->asset_id => (int) $item->asset?->lock_version])->all(),
                    'items' => $record->items->map(fn (LoanItem $item): array => [
                        'id' => $item->getKey(),
                        'label' => $item->label(),
                        'condition_out_id' => $item->condition_out_id,
                        'condition_back_id' => $item->condition_out_id,
                        'return_notes' => null,
                    ])->values()->all(),
                ];
            })
            ->schema([
                Hidden::make('versions'),
                Hidden::make('is_out'),
                Section::make('Constat au retour')
                    ->columns(2)
                    ->schema([
                        DatePicker::make('returned_on')
                            ->label('Date de retour effective')
                            ->maxDate(today())
                            ->required(),
                        Repeater::make('items')
                            ->label('Biens')
                            ->addable(false)
                            ->deletable(false)
                            ->reorderable(false)
                            ->itemLabel(fn (array $state): ?string => $state['label'] ?? null)
                            ->columnSpanFull()
                            ->schema([
                                Hidden::make('id'),
                                Hidden::make('label'),
                                Hidden::make('condition_out_id'),
                                Select::make('condition_back_id')
                                    ->label('État au retour')
                                    ->options(fn (): array => AssetCondition::query()->orderBy('sort')->pluck('label', 'id')->all())
                                    ->native(false)
                                    ->live()
                                    ->required(),
                                Textarea::make('return_notes')->label('Observations')->rows(2),
                            ]),
                        Text::make('L\'état d\'au moins un bien est moins bon qu\'au départ. L\'état du bien sera mis à jour et l\'écart restera visible dans l\'historique du prêt.')
                            ->color('warning')
                            ->visible(fn (Get $get): bool => self::degraded((array) $get('items')))
                            ->columnSpanFull(),
                        Checkbox::make('open_maintenance')
                            ->label('Ouvrir une intervention de maintenance après l\'enregistrement')
                            ->visible(fn (Get $get): bool => (bool) $get('is_out')
                                && self::degraded((array) $get('items'))
                                && (auth()->user()?->can('create', MaintenanceJob::class) ?? false))
                            ->columnSpanFull(),
                        ...array_map(
                            fn ($field) => $field->visible(fn (Get $get): bool => (bool) $get('is_out') && ($field->getName() === 'files' || filled($get('files')))),
                            OperationFields::documents('Joindre photo ou PV de retour (facultatif)', 'Document de prêt'),
                        ),
                    ]),
            ])
            ->action(function (array $data, Loan $record, Action $action): void {
                $items = collect((array) ($data['items'] ?? []))->mapWithKeys(fn (mixed $item): array => [
                    (int) ($item['id'] ?? 0) => ['condition_back_id' => $item['condition_back_id'] ?? null, 'return_notes' => $item['return_notes'] ?? null],
                ])->all();

                $versions = collect((array) ($data['versions'] ?? []))->mapWithKeys(fn (mixed $version, mixed $id): array => [(int) $id => (int) $version])->all();
                $typeId = $data['document_type_id'] ?? null;

                try {
                    $loan = app(RecordLoanReturn::class)(
                        $record,
                        ['returned_on' => $data['returned_on'] ?? null, 'items' => $items],
                        $versions,
                        $data['files'] ?? [],
                        $typeId ? DocumentType::query()->active()->find($typeId) : null,
                    );
                } catch (ValidationException|ConcurrentModificationException|AuthorizationException $exception) {
                    OperationFields::notifyFailure('Retour impossible', $exception);
                    $action->halt();

                    return;
                }

                // RG-57 : un bien rendu dans un état moins bon fait proposer une intervention.
                $degraded = $loan->items->filter(fn (LoanItem $item): bool => $item->isDegraded() && $item->asset_id !== null)->values();

                Notification::make()->success()->title('Retour enregistré')
                    ->body($degraded->isEmpty() ? null : 'État dégradé : '.$degraded->map(fn (LoanItem $item): string => $item->asset?->number.' ('.$item->conditionBack?->label.')')->join(', ').'.')
                    ->send();

                if ($degraded->isNotEmpty() && ($data['open_maintenance'] ?? false) && (auth()->user()?->can('create', MaintenanceJob::class) ?? false)) {
                    $action->redirect(MaintenanceResource::getUrl('create').'?'.http_build_query(['bien' => $degraded->first()->asset_id]));
                }
            });
    }

    /**
     * @param  array<int|string, mixed>  $items
     */
    private static function degraded(array $items): bool
    {
        $sorts = AssetCondition::query()->pluck('sort', 'id');

        foreach ($items as $item) {
            $out = $sorts[(int) ($item['condition_out_id'] ?? 0)] ?? null;
            $back = $sorts[(int) ($item['condition_back_id'] ?? 0)] ?? null;

            if ($out !== null && $back !== null && $back > $out) {
                return true;
            }
        }

        return false;
    }
}
