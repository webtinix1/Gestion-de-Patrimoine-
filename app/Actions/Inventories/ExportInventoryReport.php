<?php

namespace App\Actions\Inventories;

use App\Exports\InventoryReportExport;
use App\Models\Inventory;
use App\Models\User;
use App\Services\Inventories\InventoryReport;
use App\Services\Settings;
use App\Support\Pdf\PdfFactory;
use Barryvdh\DomPDF\PDF as DomPdf;
use Illuminate\Auth\Access\AuthorizationException;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * RG-49 : procès-verbal d'inventaire en PDF (gabarit E45, emplacements de signature) et export Excel
 * du rapport. Limités aux lignes du périmètre de l'utilisateur (RG-81).
 */
class ExportInventoryReport
{
    public function __construct(
        private InventoryReport $report,
        private Settings $settings,
    ) {}

    public function pdf(Inventory $inventory, User $user): StreamedResponse
    {
        $this->authorize($inventory, $user);
        $pdf = $this->render($inventory, $user);

        return response()->streamDownload(
            function () use ($pdf): void {
                echo $pdf->output();
            },
            $this->fileName($inventory, 'pdf'),
            ['Content-Type' => 'application/pdf'],
        );
    }

    public function excel(Inventory $inventory, User $user): BinaryFileResponse
    {
        $this->authorize($inventory, $user);

        return Excel::download(
            new InventoryReportExport($inventory, $this->report->counters($inventory), $this->report->all($inventory), InventoryReport::perimeterNote($user)),
            $this->fileName($inventory, 'xlsx'),
        );
    }

    /**
     * E20 : liste de contrôle à imprimer pour pointer sur papier (personnes désignées).
     */
    public function checklist(Inventory $inventory, User $user): StreamedResponse
    {
        if (! $user->can('enter', $inventory)) {
            throw new AuthorizationException('Vous n\'êtes pas désigné pour cet inventaire.');
        }

        $pdf = PdfFactory::view('pdf.inventory-checklist', [
            'inventory' => $inventory,
            'lines' => $this->report->all($inventory)->where('expected', true)->values(),
            'churchName' => $this->settings->string(Settings::CHURCH_NAME),
            'churchAcronym' => $this->settings->string(Settings::CHURCH_ACRONYM),
            'editedAt' => now(),
        ]);

        return response()->streamDownload(function () use ($pdf): void {
            echo $pdf->output();
        }, 'liste-controle-'.$inventory->reference.'.pdf', ['Content-Type' => 'application/pdf']);
    }

    public function render(Inventory $inventory, ?User $user = null): DomPdf
    {
        $inventory->loadMissing(['manager', 'closer', 'participants.user', 'participants.room']);

        // Hors ligne : aucune ressource distante, police DejaVu Sans incluse dans dompdf.
        return PdfFactory::view('pdf.inventory-report', [
            'inventory' => $inventory,
            'counters' => $this->report->counters($inventory),
            'sections' => $this->report->sections($inventory),
            'observations' => $this->report->observations($inventory),
            'perimeterNote' => $user === null ? null : InventoryReport::perimeterNote($user),
            'churchName' => $this->settings->string(Settings::CHURCH_NAME),
            'churchAcronym' => $this->settings->string(Settings::CHURCH_ACRONYM),
            'editedAt' => now(),
        ]);
    }

    private function authorize(Inventory $inventory, User $user): void
    {
        if (! $user->can('export', $inventory)) {
            throw new AuthorizationException('Vous n\'avez pas le droit d\'exporter ce rapport.');
        }
    }

    private function fileName(Inventory $inventory, string $extension): string
    {
        return 'inventaire-'.$inventory->reference.'-'.now()->format('Ymd-His').'.'.$extension;
    }
}
