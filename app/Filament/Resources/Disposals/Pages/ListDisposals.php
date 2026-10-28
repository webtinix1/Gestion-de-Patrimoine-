<?php

namespace App\Filament\Resources\Disposals\Pages;

use App\Enums\DisposalAccountingStatus;
use App\Filament\Resources\Disposals\DisposalResource;
use App\Models\Disposal;
use App\Support\Money;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListDisposals extends ListRecords
{
    protected static string $resource = DisposalResource::class;

    public function getSubheading(): string
    {
        $year = (int) now()->year;
        $ofYear = Disposal::query()->whereYear('disposed_on', $year);

        return sprintf(
            'Sorties %d : %d · valeur d\'origine sortie %s · VNC sortie %s · %d à valider par le comptable. Un bien sorti n\'est jamais supprimé : sa fiche, son historique et ses valeurs à la date de sortie restent consultables.',
            $year,
            (clone $ofYear)->count(),
            Money::format((int) (clone $ofYear)->sum('original_value'), withCurrency: true),
            Money::format((int) (clone $ofYear)->sum('net_book_value'), withCurrency: true),
            Disposal::query()->where('accounting_status', DisposalAccountingStatus::Pending)->count(),
        );
    }

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()->label('Enregistrer une sortie'),
        ];
    }
}
