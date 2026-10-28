<?php

namespace App\Actions\Loans;

use App\Models\Loan;
use App\Models\User;
use App\Services\Settings;
use App\Support\Pdf\PdfFactory;
use Barryvdh\DomPDF\PDF as DomPdf;
use Illuminate\Auth\Access\AuthorizationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * E45 : fiche de prêt (ou d'emprunt) à imprimer et faire signer. Elle ne liste que les biens du périmètre
 * de l'utilisateur (RG-81) et exige le droit « exporter » des prêts.
 */
class ExportLoanSheet
{
    public function __construct(private Settings $settings) {}

    public function __invoke(Loan $loan, User $user): StreamedResponse
    {
        if (! $user->can('export', $loan)) {
            throw new AuthorizationException('Vous n\'avez pas le droit d\'imprimer cette fiche.');
        }

        $pdf = $this->render($loan, $user);

        return response()->streamDownload(function () use ($pdf): void {
            echo $pdf->output();
        }, 'fiche-'.($loan->direction->value === 'in' ? 'emprunt' : 'pret').'-'.$loan->reference.'.pdf', ['Content-Type' => 'application/pdf']);
    }

    public function render(Loan $loan, ?User $user = null): DomPdf
    {
        $loan->loadMissing(['items.asset', 'items.conditionOut', 'items.conditionBack', 'responsible']);

        return PdfFactory::view('pdf.loan-sheet', [
            'loan' => $loan,
            'churchName' => $this->settings->string(Settings::CHURCH_NAME),
            'churchAcronym' => $this->settings->string(Settings::CHURCH_ACRONYM),
            'editedAt' => now(),
            'editedBy' => $user?->fullName() ?? 'Application',
        ]);
    }
}
