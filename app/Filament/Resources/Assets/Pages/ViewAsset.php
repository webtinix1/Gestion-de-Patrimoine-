<?php

namespace App\Filament\Resources\Assets\Pages;

use App\Filament\Actions\DisposeAction;
use App\Filament\Actions\TransferAction;
use App\Filament\Resources\Assets\AssetResource;
use App\Filament\Support\LabelActions;
use App\Models\Asset;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;
use Illuminate\Contracts\Support\Htmlable;

class ViewAsset extends ViewRecord
{
    protected static string $resource = AssetResource::class;

    public function getTitle(): string|Htmlable
    {
        /** @var Asset $asset */
        $asset = $this->getRecord();

        return $asset->number.' · '.$asset->designation;
    }

    protected function getHeaderActions(): array
    {
        /** @var Asset $asset */
        $asset = $this->getRecord();

        return [
            TransferAction::single($asset),
            EditAction::make(),
            LabelActions::single(),
            DisposeAction::make($asset),
        ];
    }
}
