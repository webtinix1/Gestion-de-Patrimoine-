<?php

namespace App\Services;

use App\Models\AssetCategory;
use Illuminate\Support\Facades\DB;

/**
 * Seule source des numéros patrimoniaux (RG-01 à RG-04).
 *
 * RG-02 : trois formats, PAT-000001 (global), PAT-2026-000001 (compteur annuel),
 * INF-000001 (un compteur par préfixe de catégorie). Le format choisi ne s'applique
 * qu'aux nouveaux biens.
 * RG-03 : le compteur est incrémenté sous verrou de ligne exclusif, dans une transaction ;
 * l'index unique sur assets.number reste le dernier rempart. Appelé dans une transaction
 * englobante, c'est elle qui doit prévoir les nouvelles tentatives en cas d'interblocage.
 * RG-04 : un numéro attribué à un bien enregistré n'est jamais réattribué. Si la transaction de
 * création est annulée, l'incrément l'est aussi : le numéro n'a jamais désigné de bien et
 * reste disponible, ce qui garde des numéros consécutifs.
 */
class AssetNumberGenerator
{
    public const int DIGITS = 6;

    public function __construct(private Settings $settings) {}

    public function next(AssetCategory $category): string
    {
        [$scope, $prefix] = $this->scopeAndPrefix($category);

        $value = DB::transaction(function () use ($scope): int {
            // L'incrément pose d'emblée le verrou exclusif sur la ligne du compteur. Lire puis
            // écrire (verrou partagé puis exclusif) provoque des interblocages sous MySQL/MariaDB.
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

        return $prefix.str_pad((string) $value, self::DIGITS, '0', STR_PAD_LEFT);
    }

    /**
     * Aperçu du prochain numéro, sans le réserver (affichage seulement).
     */
    public function preview(AssetCategory $category): string
    {
        [$scope, $prefix] = $this->scopeAndPrefix($category);

        $current = (int) DB::table('asset_number_sequences')->where('scope', $scope)->value('last_value');

        return $prefix.str_pad((string) ($current + 1), self::DIGITS, '0', STR_PAD_LEFT);
    }

    /**
     * Aligne un compteur sur un numéro déjà attribué (reprise de l'existant, démonstration).
     */
    public function syncWith(string $scope, int $value): void
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
            ->increment('last_value', 1, ['updated_at' => now()]);
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function scopeAndPrefix(AssetCategory $category): array
    {
        $prefix = mb_strtoupper($this->settings->string(Settings::NUMBER_PREFIX)) ?: 'PAT';

        return match ($this->settings->string(Settings::NUMBER_FORMAT)) {
            Settings::FORMAT_YEARLY => [(string) now()->year, $prefix.'-'.now()->year.'-'],
            Settings::FORMAT_CATEGORY => [$category->prefix, $category->prefix.'-'],
            default => ['global', $prefix.'-'],
        };
    }
}
