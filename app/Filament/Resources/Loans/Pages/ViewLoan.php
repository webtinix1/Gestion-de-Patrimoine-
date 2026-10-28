<?php

namespace App\Filament\Resources\Loans\Pages;

use App\Filament\Actions\LoanReturnAction;
use App\Filament\Resources\Loans\LoanResource;
use App\Models\Loan;
use Filament\Resources\Pages\ViewRecord;
use Illuminate\Contracts\Support\Htmlable;

class ViewLoan extends ViewRecord
{
    protected static string $resource = LoanResource::class;

    public function getTitle(): string|Htmlable
    {
        /** @var Loan $loan */
        $loan = $this->getRecord();

        return $loan->direction->getLabel().' '.$loan->reference;
    }

    protected function getHeaderActions(): array
    {
        return [
            LoanReturnAction::make(),
        ];
    }
}
