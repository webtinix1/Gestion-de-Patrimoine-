<?php

namespace App\Filament\Resources\LabelTemplates\Pages;

use App\Actions\Labels\SaveLabelTemplate;
use App\Filament\Resources\LabelTemplates\LabelTemplateResource;
use App\Models\LabelTemplate;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;

class EditLabelTemplate extends EditRecord
{
    protected static string $resource = LabelTemplateResource::class;

    /**
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        /** @var LabelTemplate $record */
        return app(SaveLabelTemplate::class)($data, $record);
    }
}
