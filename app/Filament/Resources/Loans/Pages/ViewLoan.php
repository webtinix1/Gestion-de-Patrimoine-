<?php

namespace App\Filament\Resources\Loans\Pages;

use App\Actions\Loans\ExportLoanSheet;
use App\Filament\Actions\LoanReturnAction;
use App\Filament\Resources\Loans\LoanResource;
use App\Filament\Support\HoldsOperationVersions;
use App\Models\Loan;
use Filament\Actions\Action;
use Filament\Resources\Pages\ViewRecord;
use Illuminate\Contracts\Support\Htmlable;
use Symfony\Component\HttpFoundation\Response;

class ViewLoan extends ViewRecord
{
    use HoldsOperationVersions;

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
            Action::make('printSheet')
                ->label('Fiche de prêt (PDF)')
                ->icon('heroicon-o-printer')
                ->color('gray')
                ->visible(fn (): bool => auth()->user()?->can('export', $this->getRecord()) ?? false)
                ->action(function (): Response {
                    /** @var Loan $loan */
                    $loan = $this->getRecord();

                    return app(ExportLoanSheet::class)($loan, auth()->user());
                }),
            LoanReturnAction::make(),
        ];
    }
}
