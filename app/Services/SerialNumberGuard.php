<?php

namespace App\Services;

use App\Models\Asset;

/**
 * RG-13 : le numéro de série, s'il est renseigné, est unique parmi les biens actifs.
 * La recherche ignore le périmètre : un doublon reste un doublon, même dans un autre département.
 */
class SerialNumberGuard
{
    public function conflictFor(mixed $serialNumber, ?int $exceptAssetId = null): ?Asset
    {
        $serialNumber = is_string($serialNumber) ? trim($serialNumber) : null;

        if ($serialNumber === null || $serialNumber === '') {
            return null;
        }

        return Asset::query()
            ->withoutGlobalScopes()
            ->where('serial_number', $serialNumber)
            ->whereNull('disposed_at')
            ->when($exceptAssetId, fn ($query) => $query->whereKeyNot($exceptAssetId))
            ->first();
    }

    public static function message(Asset $existing): string
    {
        return "Ce numéro de série est déjà enregistré sur le bien {$existing->number} ({$existing->designation}).";
    }
}
