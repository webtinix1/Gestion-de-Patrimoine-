<?php

namespace App\Services\Labels;

use App\Enums\LabelCodeType;
use App\Models\LabelTemplate;
use App\Services\Settings;
use App\Support\Labels\ComposedLabel;
use App\Support\Labels\LabelContent;
use DomainException;

/**
 * Met une étiquette en page (en mm) : le même calcul sert à l'aperçu et au PDF.
 * RG-95 : sigle (ou texte d'en-tête), numéro et code sont toujours présents ; désignation,
 * département, site et local selon le modèle, et seulement s'ils tiennent sur l'étiquette.
 *
 * @phpstan-import-type Line from ComposedLabel
 */
class LabelComposer
{
    public const float PAD_MM = 2.0;

    private const float PT_TO_MM = 0.3528;

    private const float MIN_NUMBER_PT = 6.0;

    public function __construct(
        private LabelCodeRenderer $codes,
        private Settings $settings,
    ) {}

    /**
     * Vérifie qu'un modèle peut imprimer le plus long numéro possible avec le format de numéro configuré (RG-95).
     *
     * @throws DomainException
     */
    public function assertCanPrint(LabelTemplate $template): void
    {
        $this->compose($template, new LabelContent($this->longestNumber()));
    }

    public function longestNumber(): string
    {
        $prefix = $this->settings->string(Settings::NUMBER_PREFIX) ?: 'PAT';

        return $this->settings->string(Settings::NUMBER_FORMAT) === Settings::FORMAT_YEARLY
            ? $prefix.'-2026-000001'
            : $prefix.'-000001';
    }

    public function compose(LabelTemplate $template, LabelContent $content, bool $vector = false): ComposedLabel
    {
        $width = $template->width_mm;
        $height = $template->height_mm;
        $pad = self::PAD_MM;

        $header = trim((string) $template->header_text) !== ''
            ? trim((string) $template->header_text)
            : $this->settings->string(Settings::CHURCH_ACRONYM);

        if ($template->code_type === LabelCodeType::Qr) {
            $codeSize = min($height - 2.0, $width * 0.42);
            $code = $this->codes->render($template->code_type, $content->number, $codeSize, $codeSize, $vector);
            $codeX = 1.0;
            $codeY = ($height - $code->heightMm) / 2;
            $textX = 1.0 + $code->widthMm + 1.0;
            $textTop = $pad;
            $textHeight = $height - 2 * $pad;
            $textWidth = $width - $textX - $pad;
        } else {
            $barHeight = min($height * 0.36, 14.0);
            $code = $this->codes->render($template->code_type, $content->number, $width, $barHeight, $vector);
            $codeX = ($width - $code->widthMm) / 2;
            $codeY = $height - $pad - $code->heightMm;
            $textX = $pad;
            $textTop = $pad;
            $textHeight = $codeY - 1.0 - $pad;
            $textWidth = $width - 2 * $pad;
        }

        return new ComposedLabel(
            widthMm: $width,
            heightMm: $height,
            code: $code,
            codeX: $codeX,
            codeY: $codeY,
            lines: $this->lines($template, $content, $header, $textX, $textTop, $textWidth, $textHeight, centered: $template->code_type === LabelCodeType::Qr),
        );
    }

    /**
     * @return list<Line>
     */
    private function lines(LabelTemplate $template, LabelContent $content, string $header, float $x, float $top, float $width, float $availableHeight, bool $centered): array
    {
        $candidates = [];

        if ($header !== '') {
            $candidates[] = ['text' => $header, 'pt' => 5.5, 'bold' => true, 'mono' => false];
        }

        $candidates[] = ['text' => $content->number, 'pt' => $this->numberSize($content->number, $width), 'bold' => true, 'mono' => true];

        $optional = [
            [$template->show_designation, $content->designation, '', 6.5],
            [$template->show_department, $content->department, 'Département : ', 6.0],
            [$template->show_site, $content->site, 'Site : ', 6.0],
            [$template->show_room, $content->room, 'Local : ', 6.0],
        ];

        foreach ($optional as [$shown, $value, $prefix, $pt]) {
            if ($shown && filled($value)) {
                $candidates[] = ['text' => $prefix.$value, 'pt' => $pt, 'bold' => false, 'mono' => false];
            }
        }

        // Les lignes de moindre priorité sautent si elles ne tiennent pas dans la hauteur disponible.
        while (count($candidates) > 2 && $this->stackHeight($candidates) > $availableHeight) {
            array_pop($candidates);
        }

        $y = $centered ? $top + max(0.0, ($availableHeight - $this->stackHeight($candidates)) / 2) : $top;
        $lines = [];

        foreach ($candidates as $candidate) {
            $lineHeight = $this->lineHeight($candidate['pt']);
            $lines[] = [
                'text' => $candidate['mono'] ? $candidate['text'] : $this->fit($candidate['text'], $candidate['pt'], $candidate['bold'], false, $width),
                'x' => $x,
                'y' => $y,
                'w' => $width,
                'h' => $lineHeight,
                'pt' => $candidate['pt'],
                'bold' => $candidate['bold'],
                'mono' => $candidate['mono'],
            ];
            $y += $lineHeight;
        }

        return $lines;
    }

    /**
     * @param  list<array{text: string, pt: float, bold: bool, mono: bool}>  $candidates
     */
    private function stackHeight(array $candidates): float
    {
        return array_sum(array_map(fn (array $candidate): float => $this->lineHeight($candidate['pt']), $candidates));
    }

    private function lineHeight(float $pt): float
    {
        return round($pt * self::PT_TO_MM * 1.3, 3);
    }

    /**
     * Le numéro est la ligne la plus grosse (jusqu'à 11 pt), réduite (arrondi vers le bas) pour tenir
     * entier sur une ligne. Il n'est jamais coupé : s'il ne tient pas à 6 pt, l'étiquette est refusée.
     */
    private function numberSize(string $number, float $width): float
    {
        $fits = floor($width / (max(1, mb_strlen($number)) * 0.602 * self::PT_TO_MM) * 10) / 10;

        if ($fits < self::MIN_NUMBER_PT) {
            throw new DomainException("Le numéro {$number} ne tient pas sur cette étiquette : agrandissez-la ou réduisez la place du code.");
        }

        return min(11.0, $fits);
    }

    /**
     * DomPDF ne coupe pas le texte avec des points de suspension : on tronque ici,
     * selon la largeur moyenne d'un caractère de DejaVu.
     */
    private function fit(string $text, float $pt, bool $bold, bool $mono, float $width): string
    {
        $em = $mono ? 0.602 : ($bold ? 0.72 : 0.62);
        $max = (int) floor($width / ($pt * self::PT_TO_MM * $em));

        if (mb_strlen($text) <= $max) {
            return $text;
        }

        return rtrim(mb_substr($text, 0, max(1, $max - 1))).'…';
    }
}
