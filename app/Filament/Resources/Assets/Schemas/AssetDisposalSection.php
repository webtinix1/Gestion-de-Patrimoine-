<?php

namespace App\Filament\Resources\Assets\Schemas;

use App\Filament\Resources\Disposals\DisposalResource;
use App\Models\Asset;
use App\Models\Disposal;
use App\Support\Money;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Callout;
use Filament\Schemas\Components\Section;

/**
 * E43 : fiche d'un bien sorti. Bandeau « Sorti du patrimoine » et valeurs figées à la date de sortie (RG-39).
 */
class AssetDisposalSection
{
    public static function banner(): Callout
    {
        return Callout::make('Sorti du patrimoine')
            ->description(function (Asset $record): string {
                $disposal = self::disposal($record);

                return 'Ce bien est sorti du patrimoine le '.$record->disposed_at?->format('d/m/Y')
                    .($disposal instanceof Disposal ? ' ('.$disposal->reference.', '.mb_strtolower($disposal->reason->getLabel()).')' : '')
                    .'. Fiche conservée en lecture seule. Le numéro '.$record->number.' ne sera jamais réattribué.';
            })
            ->danger()
            ->visible(fn (Asset $record): bool => $record->isDisposed());
    }

    public static function section(): Section
    {
        return Section::make('Sortie du patrimoine')
            ->columns(4)
            ->visible(fn (Asset $record): bool => self::disposal($record) instanceof Disposal)
            ->schema([
                TextEntry::make('disposal_reference')
                    ->label('N° de sortie')
                    ->state(fn (Asset $record): ?string => self::disposal($record)?->reference)
                    ->fontFamily('mono')
                    ->color('primary')
                    ->url(fn (Asset $record): ?string => ($disposal = self::disposal($record)) instanceof Disposal && (auth()->user()?->can('view', $disposal) ?? false)
                        ? DisposalResource::getUrl('view', ['record' => $disposal])
                        : null),
                TextEntry::make('disposal_reason')->label('Motif')->state(fn (Asset $record): ?string => self::disposal($record)?->reason->getLabel())->badge(),
                TextEntry::make('disposal_date')->label('Date de sortie')->state(fn (Asset $record): ?string => self::disposal($record)?->disposed_on->format('d/m/Y')),
                TextEntry::make('disposal_accounting')
                    ->label('Validation comptable')
                    ->state(fn (Asset $record): ?string => self::disposal($record)?->accounting_status->getLabel())
                    ->badge()
                    ->color(fn (Asset $record): string => self::disposal($record)?->accounting_status->getColor() ?? 'gray'),
                TextEntry::make('disposal_original_value')
                    ->label('Valeur d\'origine')
                    ->state(fn (Asset $record): string => Money::format(self::disposal($record)?->original_value, withCurrency: true))
                    ->fontFamily('mono'),
                TextEntry::make('disposal_accumulated')
                    ->label('Amort. cumulés à la sortie')
                    ->state(fn (Asset $record): string => Money::format(self::disposal($record)?->accumulated_depreciation, withCurrency: true))
                    ->fontFamily('mono'),
                TextEntry::make('disposal_net_book_value')
                    ->label('VNC à la sortie')
                    ->state(fn (Asset $record): string => Money::format(self::disposal($record)?->net_book_value, withCurrency: true))
                    ->fontFamily('mono')
                    ->weight('bold'),
                TextEntry::make('disposal_decided_by')
                    ->label('Décision prise par')
                    ->state(fn (Asset $record): ?string => self::disposal($record)?->decided_by),
            ]);
    }

    private static function disposal(Asset $asset): ?Disposal
    {
        if (! $asset->isDisposed()) {
            return null;
        }

        // Le bien est visible : sa sortie l'est aussi (même périmètre).
        return $asset->relationLoaded('disposal') ? $asset->disposal : $asset->load('disposal')->disposal;
    }
}
