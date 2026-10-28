<?php

namespace App\Filament\Resources\Inventories\Pages;

use App\Enums\InventoryStatus;
use App\Filament\Resources\Inventories\InventoryResource;
use App\Models\Inventory;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListInventories extends ListRecords
{
    protected static string $resource = InventoryResource::class;

    public function getTitle(): string
    {
        return 'Inventaires physiques';
    }

    public function getSubheading(): string
    {
        $current = Inventory::query()->where('status', InventoryStatus::InProgress)->count();

        return 'Comparer ce que dit l\'application avec ce qui est réellement sur place.'
            .($current > 0 ? ' '.$current.' inventaire'.($current > 1 ? 's' : '').' en cours.' : '');
    }

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()->label('Nouvel inventaire'),
        ];
    }
}
