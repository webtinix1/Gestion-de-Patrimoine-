<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * Référence SRT-2026-023 : un compteur par année (portée « SRT-2026 » de la table des compteurs),
 * incrémenté d'abord sous verrou de ligne exclusif, comme le numéro patrimonial. Appelé dans la
 * transaction de la sortie, qui garde le verrou jusqu'à son terme : jamais deux fois la même
 * référence, jamais réattribuée.
 */
class DisposalReferenceGenerator
{
    public function next(int $year): string
    {
        $scope = 'SRT-'.$year;

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
        });

        return sprintf('%s-%03d', $scope, $value);
    }

    private function increment(string $scope): int
    {
        return DB::table('asset_number_sequences')
            ->where('scope', $scope)
            ->update(['last_value' => DB::raw('last_value + 1'), 'updated_at' => now()]);
    }
}
