<?php

namespace App\Support;

/**
 * Contrat avec la page d'impression des étiquettes : /admin/etiquettes?biens=<id1>,<id2>,...
 */
final class LabelsUrl
{
    /**
     * @param  iterable<int>  $assetIds
     */
    public static function for(iterable $assetIds): string
    {
        $ids = collect($assetIds)->map(fn (int $id): int => $id)->implode(',');

        return url('/admin/etiquettes').($ids === '' ? '' : '?biens='.$ids);
    }
}
