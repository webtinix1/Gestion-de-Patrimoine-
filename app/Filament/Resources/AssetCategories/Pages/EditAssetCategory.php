<?php

namespace App\Filament\Resources\AssetCategories\Pages;

use App\Filament\Resources\AssetCategories\AssetCategoryResource;
use App\Filament\Support\Referentials;
use Filament\Resources\Pages\EditRecord;

class EditAssetCategory extends EditRecord
{
    protected static string $resource = AssetCategoryResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Referentials::deleteAction(),
        ];
    }
}
