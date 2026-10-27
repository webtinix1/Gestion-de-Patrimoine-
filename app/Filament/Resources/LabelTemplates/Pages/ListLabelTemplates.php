<?php

namespace App\Filament\Resources\LabelTemplates\Pages;

use App\Filament\Pages\LabelPrinting;
use App\Filament\Resources\LabelTemplates\LabelTemplateResource;
use App\Models\LabelTemplate;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Support\Facades\Gate;

class ListLabelTemplates extends ListRecords
{
    protected static string $resource = LabelTemplateResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('print')
                ->label('Impression des étiquettes')
                ->color('gray')
                ->icon('heroicon-o-printer')
                ->url(LabelPrinting::getUrl())
                ->visible(fn (): bool => Gate::allows('print', LabelTemplate::class)),
            CreateAction::make(),
        ];
    }
}
