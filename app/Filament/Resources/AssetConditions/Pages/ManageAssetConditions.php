<?php

namespace App\Filament\Resources\AssetConditions\Pages;

use App\Filament\Resources\AssetConditions\AssetConditionResource;
use App\Models\AssetCondition;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManageAssetConditions extends ManageRecords
{
    protected static string $resource = AssetConditionResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->mutateDataUsing(function (array $data): array {
                    $data['sort'] = (int) AssetCondition::query()->max('sort') + 1;

                    return $data;
                }),
        ];
    }
}
