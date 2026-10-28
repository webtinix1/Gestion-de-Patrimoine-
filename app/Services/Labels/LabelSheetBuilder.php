<?php

namespace App\Services\Labels;

use App\Models\LabelTemplate;
use App\Support\Labels\LabelContent;
use App\Support\Labels\SheetSlot;
use InvalidArgumentException;

/**
 * RG-96 : place les étiquettes sur les pages. Planche : grille centrée sur l'A4, on peut commencer
 * à une case donnée pour réutiliser une planche entamée. Rouleau : une étiquette par page, à la taille exacte.
 */
class LabelSheetBuilder
{
    public function __construct(private LabelComposer $composer) {}

    /**
     * Nombre d'étiquettes par page : 24 pour une 3 × 8, 1 pour un rouleau.
     */
    public function perPage(LabelTemplate $template): int
    {
        return $template->sheet_layout->perPage();
    }

    /**
     * Retourne les pages, chacune avec toutes ses cases (les cases vides ont un label null).
     * La case de départ ne vaut que pour la première planche.
     *
     * @param  list<LabelContent>  $labels  une entrée par étiquette à imprimer, copies comprises
     * @return list<list<SheetSlot>>
     */
    public function pages(LabelTemplate $template, array $labels, int $startCell = 1, bool $vector = false): array
    {
        $layout = $template->sheet_layout;
        $perPage = $layout->perPage();

        if (! $layout->isSheet()) {
            $startCell = 1;
        }

        if ($startCell < 1 || $startCell > $perPage) {
            throw new InvalidArgumentException("La case de départ doit être comprise entre 1 et {$perPage}.");
        }

        $queue = array_fill(0, $startCell - 1, null);

        foreach ($labels as $label) {
            $queue[] = $label;
        }

        $pages = [];

        foreach (array_chunk($queue, $perPage) as $chunk) {
            $slots = [];

            for ($index = 0; $index < $perPage; $index++) {
                $content = $chunk[$index] ?? null;
                [$x, $y] = $this->origin($template, $index);

                $slots[] = new SheetSlot(
                    number: $index + 1,
                    xMm: $x,
                    yMm: $y,
                    widthMm: $template->width_mm,
                    heightMm: $template->height_mm,
                    label: $content === null ? null : $this->composer->compose($template, $content, $vector),
                );
            }

            $pages[] = $slots;
        }

        return $pages;
    }

    /**
     * Coin supérieur gauche de la case (index à partir de 0), en mm depuis le coin de la page.
     *
     * @return array{0: float, 1: float}
     */
    public function origin(LabelTemplate $template, int $index): array
    {
        $layout = $template->sheet_layout;

        if (! $layout->isSheet()) {
            return [0.0, 0.0];
        }

        $columns = $layout->columns();
        $left = ($layout::A4_WIDTH_MM - $columns * $template->width_mm) / 2;
        $top = ($layout::A4_HEIGHT_MM - $layout->rows() * $template->height_mm) / 2;

        return [
            round($left + ($index % $columns) * $template->width_mm, 3),
            round($top + intdiv($index, $columns) * $template->height_mm, 3),
        ];
    }

    /**
     * Dimensions de la page en mm : A4 pour une planche, la taille de l'étiquette pour un rouleau.
     *
     * @return array{0: float, 1: float}
     */
    public function pageSize(LabelTemplate $template): array
    {
        $layout = $template->sheet_layout;

        return $layout->isSheet()
            ? [$layout::A4_WIDTH_MM, $layout::A4_HEIGHT_MM]
            : [$template->width_mm, $template->height_mm];
    }

    /**
     * Le modèle tient-il sur son support ? (colonnes × largeur ≤ 210 mm, lignes × hauteur ≤ 297 mm)
     */
    public function fits(LabelTemplate $template): bool
    {
        $layout = $template->sheet_layout;

        if (! $layout->isSheet()) {
            return true;
        }

        return $layout->columns() * $template->width_mm <= $layout::A4_WIDTH_MM
            && $layout->rows() * $template->height_mm <= $layout::A4_HEIGHT_MM;
    }

    /**
     * "cases 5 à 12" pour le journal et l'aperçu ; null pour un rouleau.
     */
    public function cellRange(LabelTemplate $template, int $count, int $startCell): ?string
    {
        if (! $template->sheet_layout->isSheet() || $count < 1) {
            return null;
        }

        $perPage = $template->sheet_layout->perPage();
        $first = $startCell;
        $last = $startCell + $count - 1;

        if ($last > $perPage) {
            return sprintf('à partir de la case %d, sur %d planches', $first, intdiv($last - 1, $perPage) + 1);
        }

        return $first === $last ? "case {$first}" : "cases {$first} à {$last}";
    }
}
