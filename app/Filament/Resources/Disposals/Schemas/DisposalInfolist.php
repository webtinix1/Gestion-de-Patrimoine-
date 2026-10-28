<?php

namespace App\Filament\Resources\Disposals\Schemas;

use App\Enums\DisposalReason;
use App\Filament\Resources\Assets\AssetResource;
use App\Models\Disposal;
use App\Models\Document;
use App\Support\Money;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Schema;
use Illuminate\Support\HtmlString;

/**
 * E26 / E27 : fiche d'une sortie, en lecture seule (RG-53). Valeurs figées à la date de sortie (RG-39) ;
 * plus ou moins-value indicative pour une cession, sans écriture comptable (Q8).
 */
class DisposalInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(3)
            ->components([
                Section::make('Sortie du patrimoine')
                    ->columnSpan(['default' => 3, 'lg' => 2])
                    ->columns(3)
                    ->schema([
                        TextEntry::make('reference')->label('N° de sortie')->fontFamily('mono')->weight('bold')->color('primary'),
                        TextEntry::make('reason')->label('Motif')->badge(),
                        TextEntry::make('disposed_on')->label('Date de sortie')->date('d/m/Y'),
                        TextEntry::make('asset.number')
                            ->label('Bien')
                            ->fontFamily('mono')
                            ->url(fn (Disposal $record): string => AssetResource::getUrl('view', ['record' => $record->asset_id])),
                        TextEntry::make('asset_label')->label('Désignation')->state(fn (Disposal $record): string => $record->asset->label())->columnSpan(2),
                        TextEntry::make('previous_status')->label('Statut avant la sortie')->badge(),
                        TextEntry::make('asset.status')->label('Statut après la sortie')->badge(),
                        TextEntry::make('decided_by')->label('Décision prise par'),
                        TextEntry::make('decision_reference')->label('Référence de la décision')->placeholder('—'),
                        TextEntry::make('buyer_or_beneficiary')
                            ->label(fn (Disposal $record): string => $record->reason->counterpartyLabel())
                            ->visible(fn (Disposal $record): bool => $record->reason->requiresCounterparty()),
                        TextEntry::make('sale_price')
                            ->label('Prix de cession')
                            ->formatStateUsing(fn (?int $state): string => Money::format($state, withCurrency: true))
                            ->fontFamily('mono')
                            ->visible(fn (Disposal $record): bool => $record->reason === DisposalReason::Sale),
                        TextEntry::make('notes')->label('Observations')->placeholder('—')->columnSpanFull(),
                        TextEntry::make('recorded_by')
                            ->label('Enregistrée par')
                            ->state(fn (Disposal $record): string => ($record->creator?->fullName() ?? 'Système').' · '.$record->created_at?->format('d/m/Y H:i')),
                        TextEntry::make('accounting_status')
                            ->label('Validation comptable')
                            ->badge()
                            ->formatStateUsing(fn (Disposal $record): string => $record->isValidated()
                                ? 'Validée · '.($record->validator?->fullName() ?? '—').', '.$record->accounting_validated_at?->format('d/m/Y')
                                : 'À valider'),
                    ]),
                Section::make('Valeurs à la date de sortie')
                    ->columnSpan(['default' => 3, 'lg' => 1])
                    ->schema([
                        TextEntry::make('original_value')
                            ->label('Valeur d\'origine')
                            ->formatStateUsing(fn (int $state): string => Money::format($state, withCurrency: true))
                            ->fontFamily('mono'),
                        TextEntry::make('accumulated_depreciation')
                            ->label(fn (Disposal $record): string => 'Amortissements cumulés au '.$record->disposed_on->format('d/m/Y'))
                            ->formatStateUsing(fn (int $state): string => Money::format($state, withCurrency: true))
                            ->fontFamily('mono'),
                        TextEntry::make('net_book_value')
                            ->label('VNC à la sortie')
                            ->formatStateUsing(fn (int $state): string => Money::format($state, withCurrency: true))
                            ->fontFamily('mono')
                            ->weight('bold'),
                        TextEntry::make('indicative_gain')
                            ->label(fn (Disposal $record): string => ($record->indicativeGain() ?? 0) < 0 ? 'Moins-value indicative' : 'Plus-value indicative')
                            ->state(fn (Disposal $record): string => Money::format(abs((int) $record->indicativeGain()), withCurrency: true))
                            ->color(fn (Disposal $record): string => ($record->indicativeGain() ?? 0) < 0 ? 'danger' : 'success')
                            ->fontFamily('mono')
                            ->helperText('Indicatif : aucune écriture comptable n\'est générée.')
                            ->visible(fn (Disposal $record): bool => $record->indicativeGain() !== null),
                        Text::make('Valeurs figées à l\'enregistrement de la sortie (RG-39) : elles ne changent plus.'),
                    ]),
                Section::make('Justificatifs')
                    ->columnSpanFull()
                    ->schema([
                        Text::make(fn (Disposal $record): HtmlString => self::documents($record)),
                    ]),
            ]);
    }

    private static function documents(Disposal $disposal): HtmlString
    {
        $documents = $disposal->documents()->with('type')->orderBy('documents.id')->get();

        if ($documents->isEmpty()) {
            return new HtmlString('<span class="text-sm text-gray-500">Aucun justificatif.</span>');
        }

        return new HtmlString('<ul class="space-y-1 text-sm">'.$documents->map(fn (Document $document): string => '<li><a class="font-semibold text-primary-600 underline" href="'
            .e($document->fileUrl()).'" target="_blank">'.e($document->original_name).'</a> · '.e($document->type->label).' · '.e($document->humanSize()).'</li>')->join('').'</ul>');
    }
}
