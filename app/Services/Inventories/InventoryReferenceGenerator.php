<?php

namespace App\Services\Inventories;

use Illuminate\Support\Facades\DB;

/**
 * Référence INV-2026-005 : un compteur par année dans la table des compteurs (portée « INV-2026 »),
 * incrémenté sous verrou de ligne exclusif, comme ACQ-2026-039. Appelé dans la transaction de création,
 * qui garde le verrou jusqu'à son terme : jamais deux fois la même référence, jamais réattribuée.
 */
class InventoryReferenceGenerator
{
    public function next(int $year): string
    {
        $scope = 'INV-'.$year;

        $value = DB::transaction(function () use ($scope): int {
            if ($this->increment($scope) === 0) {
                DB::table('asset_number_sequences')->insertOrIgnore([
                    'scope' => $scope,
                    'last_value' => 0,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                $this->increment($scope);
            }

            return (int) DB::table('asset_number_sequences')->where('scope', $scope)->value('last_value');
        }, attempts: 5);

        return sprintf('%s-%03d', $scope, $value);
    }

    /**
     * Référence que recevrait le prochain inventaire de l'année (affichage seulement).
     */
    public function preview(int $year): string
    {
        $value = (int) DB::table('asset_number_sequences')->where('scope', 'INV-'.$year)->value('last_value');

        return sprintf('INV-%d-%03d', $year, $value + 1);
    }

    /**
     * Aligne le compteur d'une année sur une référence déjà attribuée (démonstration).
     */
    public function syncWith(int $year, int $value): void
    {
        $scope = 'INV-'.$year;

        DB::table('asset_number_sequences')->insertOrIgnore([
            'scope' => $scope, 'last_value' => 0, 'created_at' => now(), 'updated_at' => now(),
        ]);

        DB::table('asset_number_sequences')
            ->where('scope', $scope)
            ->where('last_value', '<', $value)
            ->update(['last_value' => $value, 'updated_at' => now()]);
    }

    private function increment(string $scope): int
    {
        return DB::table('asset_number_sequences')
            ->where('scope', $scope)
            ->update(['last_value' => DB::raw('last_value + 1'), 'updated_at' => now()]);
    }
}
