<?php

namespace App\Filament\Resources\AssetCategories\Pages;

use App\Filament\Resources\AssetCategories\AssetCategoryResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListAssetCategories extends ListRecords
{
    protected static string $resource = AssetCategoryResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
