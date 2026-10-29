<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * Référence ACQ-2026-039 : un compteur par année, incrémenté sous verrou de ligne exclusif.
 * Il partage la table des compteurs du numéro patrimonial (portée « ACQ-2026 »).
 * Appelé dans la transaction de création, qui garde le verrou jusqu'à son terme : deux
 * créations simultanées reçoivent deux références différentes, jamais réattribuées.
 */
class AcquisitionReferenceGenerator
{
    public function next(int $year): string
    {
        $scope = 'ACQ-'.$year;

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
     * Aligne le compteur d'une année sur une référence déjà attribuée (démonstration).
     */
    public function syncWith(int $year, int $value): void
    {
        $scope = 'ACQ-'.$year;

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
            ->increment('last_value', 1, ['updated_at' => now()]);
    }
}
