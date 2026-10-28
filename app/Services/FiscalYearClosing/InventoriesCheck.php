<?php

namespace App\Services\FiscalYearClosing;

use App\Enums\InventoryStatus;
use App\Models\FiscalYear;
use App\Models\Inventory;

/**
 * RG-42 : inventaires non clôturés commencés au plus tard le dernier jour de l'exercice. Point à
 * confirmer, il n'empêche pas la clôture. Compté sur tous les inventaires, quel que soit le périmètre.
 */
class InventoriesCheck implements AttentionPointProvider
{
    public function points(FiscalYear $fiscalYear): array
    {
        $open = Inventory::query()->withoutGlobalScopes()
            ->where('status', '!=', InventoryStatus::Closed)
            ->whereDate('starts_on', '<=', $fiscalYear->ends_on)
            ->orderBy('reference')
            ->pluck('reference');

        if ($open->isEmpty()) {
            return [new AttentionPoint('inventories', 'Aucun inventaire non clôturé', AttentionPoint::OK)];
        }

        $count = $open->count();

        return [new AttentionPoint(
            'inventories',
            $count.' inventaire'.($count > 1 ? 's' : '').' non clôturé'.($count > 1 ? 's' : '').' : '.$open->join(', '),
            AttentionPoint::WARNING,
        )];
    }
}
