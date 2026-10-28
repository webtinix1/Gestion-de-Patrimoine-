<?php

namespace App\Filament\Resources\Activities\Pages;

use App\Filament\Resources\Activities\ActivityResource;
use Filament\Resources\Pages\ListRecords;

class ListActivities extends ListRecords
{
    protected static string $resource = ActivityResource::class;

    public function getSubheading(): string
    {
        return 'Trace non modifiable des opérations importantes : qui, quand, quoi, avant et après.';
    }
}
