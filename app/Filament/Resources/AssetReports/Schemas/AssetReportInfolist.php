<?php

namespace App\Filament\Resources\AssetReports\Schemas;

use App\Filament\Resources\Assets\AssetResource;
use App\Filament\Resources\Maintenance\MaintenanceResource;
use App\Models\AssetReport;
use App\Models\MaintenanceJob;
use Filament\Infolists\Components\ImageEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

/**
 * E24 : fiche d'un signalement, avec son statut et l'opération créée pour le traiter (visible du responsable).
 */
class AssetReportInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Signalement')
                ->columns(3)
                ->columnSpanFull()
                ->schema([
                    TextEntry::make('reference')->label('Référence')->fontFamily('mono')->weight('semibold'),
                    TextEntry::make('status')->label('Statut')->badge(),
                    TextEntry::make('urgency')->label('Urgence')->badge(),
                    TextEntry::make('type')->label('Nature')->badge(),
                    TextEntry::make('asset.number')
                        ->label('Bien')
                        ->fontFamily('mono')
                        ->helperText(fn (AssetReport $record): ?string => $record->asset?->label())
                        ->url(fn (AssetReport $record): string => AssetResource::getUrl('view', ['record' => $record->asset_id])),
                    TextEntry::make('creator.first_name')
                        ->label('Signalé par')
                        ->state(fn (AssetReport $record): ?string => $record->creator?->fullName())
                        ->helperText(fn (AssetReport $record): string => $record->created_at?->format('d/m/Y H:i') ?? ''),
                    TextEntry::make('reportedCondition.label')->label('État constaté')->visible(fn (AssetReport $record): bool => $record->reported_condition_id !== null),
                    TextEntry::make('reportedRoom.name')->label('Local indiqué')->visible(fn (AssetReport $record): bool => $record->reported_room_id !== null),
                    TextEntry::make('reportedDepartment.name')->label('Département indiqué')->visible(fn (AssetReport $record): bool => $record->reported_department_id !== null),
                    TextEntry::make('description')->label('Description')->columnSpanFull(),
                    ImageEntry::make('photo_path')
                        ->label('Photo')
                        ->state(fn (AssetReport $record): ?string => $record->photo_path
                            ? route('filament.admin.reports.photo', ['report' => $record->getKey()])
                            : null)
                        ->visible(fn (AssetReport $record): bool => $record->photo_path !== null)
                        ->columnSpanFull(),
                ]),
            Section::make('Traitement')
                ->columns(3)
                ->columnSpanFull()
                ->schema([
                    TextEntry::make('handler.first_name')
                        ->label('Traité par')
                        ->state(fn (AssetReport $record): ?string => $record->handler?->fullName())
                        ->placeholder('—'),
                    TextEntry::make('handled_at')->label('Le')->dateTime('d/m/Y H:i')->placeholder('—'),
                    TextEntry::make('operation')
                        ->label('Opération liée')
                        ->state(fn (AssetReport $record): ?string => match (true) {
                            $record->handledRef instanceof MaintenanceJob => 'Intervention '.$record->handledRef->reference,
                            $record->handledRef !== null => class_basename($record->handledRef).' n° '.$record->handledRef->getKey(),
                            default => null,
                        })
                        ->url(fn (AssetReport $record): ?string => $record->handledRef instanceof MaintenanceJob
                            ? MaintenanceResource::getUrl('view', ['record' => $record->handledRef])
                            : null)
                        ->placeholder('—'),
                    TextEntry::make('rejection_reason')
                        ->label('Motif du rejet')
                        ->visible(fn (AssetReport $record): bool => $record->rejection_reason !== null)
                        ->columnSpanFull(),
                ]),
        ]);
    }
}
