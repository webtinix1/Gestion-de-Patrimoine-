<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * Références PRT-2026-064, EMP-2026-004, MNT-2026-052, SIG-2026-031 (un compteur par préfixe et par année)
 * et EXT-0001 (biens empruntés), dans la table des compteurs du numéro patrimonial, comme
 * AssetNumberGenerator : l'incrément pose d'emblée le verrou exclusif, la transaction englobante le garde
 * jusqu'à son terme et prévoit les nouvelles tentatives. Une référence n'est jamais réutilisée.
 */
class OperationReferenceGenerator
{
    // Plus long que les préfixes de catégorie (trois lettres) : aucune collision avec les numéros patrimoniaux.
    private const string EXTERNAL_SCOPE = 'EXTERNAL';

    public function next(string $prefix, int $year): string
    {
        $scope = $prefix.'-'.$year;

        return sprintf('%s-%03d', $scope, $this->advance($scope));
    }

    // RG-58 : référence d'un bien emprunté, hors numérotation patrimoniale.
    public function nextExternal(): string
    {
        return sprintf('EXT-%04d', $this->advance(self::EXTERNAL_SCOPE));
    }

    public function syncWith(string $prefix, int $year, int $value): void
    {
        $this->syncScope($prefix.'-'.$year, $value);
    }

    public function syncExternal(int $value): void
    {
        $this->syncScope(self::EXTERNAL_SCOPE, $value);
    }

    private function advance(string $scope): int
    {
        return DB::transaction(function () use ($scope): int {
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
    }

    private function syncScope(string $scope, int $value): void
    {
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
