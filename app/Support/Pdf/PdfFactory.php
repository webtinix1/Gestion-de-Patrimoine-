<?php

namespace App\Support\Pdf;

use Barryvdh\DomPDF\Facade\Pdf;
use Barryvdh\DomPDF\PDF as DomPdf;

/**
 * E45 : réglages communs des documents imprimés. Hors ligne : aucune ressource distante, aucun PHP dans
 * les gabarits, police DejaVu Sans incluse dans dompdf.
 */
final class PdfFactory
{
    /**
     * @param  array<string, mixed>  $data
     */
    public static function view(string $view, array $data, bool $landscape = false): DomPdf
    {
        return Pdf::loadView($view, $data)
            ->setPaper('a4', $landscape ? 'landscape' : 'portrait')
            ->setOption([
                'enable_remote' => false,
                'enable_php' => false,
                'default_font' => 'DejaVu Sans',
            ]);
    }
}
