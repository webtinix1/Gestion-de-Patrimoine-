<?php

namespace App\Support\Pdf;

use Barryvdh\DomPDF\Facade\Pdf;
use Barryvdh\DomPDF\PDF as DomPdf;

/**
 * E45 : réglages communs des documents imprimés. Hors ligne : aucune ressource distante, aucun PHP dans
 * les gabarits, police DejaVu Sans incluse dans dompdf. Le « Page x / y » du pied de page est écrit par
 * le canvas après la mise en page (dompdf ne connaît pas le nombre total de pages en CSS).
 */
final class PdfFactory
{
    /** Marge droite du gabarit (32 px), en points. */
    private const float RIGHT_MARGIN = 24.0;

    /**
     * @param  array<string, mixed>  $data
     */
    public static function view(string $view, array $data, bool $landscape = false, bool $paginate = true): DomPdf
    {
        $pdf = Pdf::loadView($view, $data)
            ->setPaper('a4', $landscape ? 'landscape' : 'portrait')
            ->setOption([
                'enable_remote' => false,
                'enable_php' => false,
                'default_font' => 'DejaVu Sans',
            ]);

        if ($paginate) {
            self::paginate($pdf);
        }

        return $pdf;
    }

    public static function paginate(DomPdf $pdf): void
    {
        $pdf->render();
        $dompdf = $pdf->getDomPDF();
        $canvas = $dompdf->getCanvas();
        $font = $dompdf->getFontMetrics()->getFont('DejaVu Sans');
        $size = 7.5;
        $label = 'PAGE {PAGE_NUM} / {PAGE_COUNT}';
        $width = $dompdf->getFontMetrics()->getTextWidth('PAGE 00 / 00', $font, $size);

        $canvas->page_text($canvas->get_width() - self::RIGHT_MARGIN - $width, $canvas->get_height() - 25.0, $label, $font, $size, [0.41, 0.41, 0.41]);
    }
}
