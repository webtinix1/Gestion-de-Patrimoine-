<?php

namespace App\Filament\Resources\Maintenance\Pages;

use App\Actions\Maintenance\UpdateMaintenanceJob;
use App\Exceptions\ConcurrentModificationException;
use App\Filament\Resources\Maintenance\MaintenanceResource;
use App\Filament\Support\DetectsConcurrentEdits;
use App\Filament\Support\OperationFields;
use App\Models\MaintenanceJob;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

class EditMaintenanceJob extends EditRecord
{
    use DetectsConcurrentEdits;

    protected static string $resource = MaintenanceResource::class;

    public function getTitle(): string|Htmlable
    {
        /** @var MaintenanceJob $job */
        $job = $this->getRecord();

        return 'Modifier '.$job->reference;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        /** @var MaintenanceJob $record */
        try {
            return app(UpdateMaintenanceJob::class)($record, $data, $this->openedLockVersion);
        } catch (ConcurrentModificationException $exception) {
            $this->notifyConcurrentModification($exception);
        } catch (ValidationException|AuthorizationException $exception) {
            OperationFields::notifyFailure('Modification impossible', $exception);

            $this->halt();
        }

        return $record;
    }

    protected function getRedirectUrl(): string
    {
        return MaintenanceResource::getUrl('view', ['record' => $this->getRecord()]);
    }
}
