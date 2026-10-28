<?php

namespace App\Filament\Resources\LabelTemplates\Pages;

use App\Actions\Labels\SaveLabelTemplate;
use App\Filament\Resources\LabelTemplates\LabelTemplateResource;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreateLabelTemplate extends CreateRecord
{
    protected static string $resource = LabelTemplateResource::class;

    /**
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordCreation(array $data): Model
    {
        return app(SaveLabelTemplate::class)($data);
    }
}
