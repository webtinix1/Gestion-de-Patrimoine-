<?php

namespace App\Filament\Resources\Assets\Pages;

use App\Actions\Assets\ExportAssetsToExcel;
use App\Actions\Assets\ExportAssetsToPdf;
use App\Filament\Resources\Assets\AssetResource;
use App\Models\Asset;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Filament\Tables\Filters\Indicator;
use Symfony\Component\HttpFoundation\Response;

class ListAssets extends ListRecords
{
    protected static string $resource = AssetResource::class;

    public function getSubheading(): string
    {
        $total = Asset::query()->count();
        $disposed = Asset::query()->whereNotNull('disposed_at')->count();

        return sprintf('%d biens enregistrés · %d actifs · %d sortis du patrimoine', $total, $total - $disposed, $disposed);
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('exportExcel')
                ->label('Exporter Excel')
                ->icon('heroicon-o-table-cells')
                ->color('gray')
                ->visible(fn (): bool => $this->canExport())
                ->action(fn (): Response => app(ExportAssetsToExcel::class)($this->getTableQueryForExport(), $this->activeFilterLabels())),
            Action::make('exportPdf')
                ->label('Exporter PDF')
                ->icon('heroicon-o-document-text')
                ->color('gray')
                ->visible(fn (): bool => $this->canExport())
                ->action(fn (): Response => app(ExportAssetsToPdf::class)($this->getTableQueryForExport(), $this->activeFilterLabels())),
            CreateAction::make()->label('Nouveau bien'),
        ];
    }

    private function canExport(): bool
    {
        return auth()->user()?->can('export', Asset::class) ?? false;
    }

    /**
     * @return list<string>
     */
    private function activeFilterLabels(): array
    {
        return collect($this->getTable()->getFilterIndicators())
            ->map(fn (Indicator $indicator): string => (string) $indicator->getLabel())
            ->values()
            ->all();
    }
}
