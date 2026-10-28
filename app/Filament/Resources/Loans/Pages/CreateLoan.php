<?php

namespace App\Filament\Resources\Loans\Pages;

use App\Actions\Loans\CreateLoan as CreateLoanAction;
use App\Enums\LoanDirection;
use App\Exceptions\ConcurrentModificationException;
use App\Filament\Resources\Loans\LoanResource;
use App\Filament\Support\HoldsOperationVersions;
use App\Filament\Support\OperationFields;
use App\Models\Asset;
use App\Models\DocumentType;
use App\Models\Loan;
use Filament\Resources\Pages\CreateRecord;
use Filament\Support\Exceptions\Halt;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;
use Illuminate\Validation\ValidationException;

class CreateLoan extends CreateRecord
{
    use HoldsOperationVersions;

    protected static string $resource = LoanResource::class;

    protected static ?string $title = 'Enregistrer un prêt ou un emprunt';

    protected static bool $canCreateAnother = false;

    public function mount(): void
    {
        parent::mount();

        // Fiche ouverte depuis « Prêter » : la version du bien présélectionné est retenue dès l'ouverture.
        if (request()->integer('bien') > 0) {
            $this->holdVersions(Asset::query()->whereKey(request()->integer('bien'))->pluck('lock_version', 'id')->all());
        }
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordCreation(array $data): Model
    {
        $files = Arr::pull($data, 'files');
        $typeId = Arr::pull($data, 'document_type_id');
        $assetIds = (array) Arr::pull($data, 'asset_ids');

        try {
            $versions = OperationFields::heldVersions($this, $assetIds);

            return app(CreateLoanAction::class)(
                $data,
                $assetIds,
                $versions,
                $files ?? [],
                $typeId ? DocumentType::query()->active()->find($typeId) : null,
            );
        } catch (ValidationException|ConcurrentModificationException|AuthorizationException $exception) {
            OperationFields::notifyFailure('Enregistrement impossible', $exception);

            throw new Halt;
        }
    }

    protected function getCreatedNotificationTitle(): ?string
    {
        /** @var Loan $loan */
        $loan = $this->getRecord();

        return ($loan->direction === LoanDirection::Out ? 'Prêt ' : 'Emprunt ').$loan->reference.' enregistré';
    }

    protected function getRedirectUrl(): string
    {
        return LoanResource::getUrl('view', ['record' => $this->getRecord()]);
    }
}
