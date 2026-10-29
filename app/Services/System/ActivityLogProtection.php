<?php

namespace App\Services\System;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * RG-70 : déclencheurs qui refusent toute modification ou suppression du journal des opérations dans la
 * base elle-même (requête directe, commande activitylog:clean). Le modèle Activity le refuse déjà.
 *
 * MySQL et MariaDB, journal binaire actif (le défaut), exigent le privilège SUPER pour créer un déclencheur,
 * sauf si log_bin_trust_function_creators vaut 1. Le compte dédié de l'application ne l'a pas : la pose est
 * alors reportée, sans bloquer l'installation, et `patrimoine:verifier` le signale.
 */
class ActivityLogProtection
{
    public const array TRIGGERS = [
        'activity_log_no_update' => 'UPDATE',
        'activity_log_no_delete' => 'DELETE',
    ];

    public const string MESSAGE = 'Le journal des opérations ne peut être ni modifié ni supprimé.';

    // Refus faute de privilège : SUPER exigé avec le journal binaire (1419), droit refusé (1227, 1142).
    private const array PRIVILEGE_ERRORS = [1419, 1227, 1142];

    public const string ADVICE = 'Ajoutez log_bin_trust_function_creators=1 dans la section [mysqld] de my.ini, redémarrez MySQL ou MariaDB, puis lancez php artisan patrimoine:verifier --reparer.';

    /**
     * Pose les déclencheurs manquants. Faux si la base refuse faute de privilège ; toute autre erreur remonte.
     */
    public function install(): bool
    {
        $driver = DB::getDriverName();

        if (! in_array($driver, ['sqlite', 'mysql', 'mariadb'], true)) {
            return false;
        }

        $existing = $this->existing();

        try {
            foreach (self::TRIGGERS as $name => $event) {
                if (in_array($name, $existing, true)) {
                    continue;
                }

                DB::unprepared($driver === 'sqlite'
                    ? "CREATE TRIGGER {$name} BEFORE {$event} ON activity_log BEGIN SELECT RAISE(ABORT, '".self::MESSAGE."'); END"
                    : "CREATE TRIGGER {$name} BEFORE {$event} ON activity_log FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = '".self::MESSAGE."'");
            }
        } catch (QueryException $e) {
            if (in_array((int) ($e->errorInfo[1] ?? 0), self::PRIVILEGE_ERRORS, true)) {
                return false;
            }

            throw $e;
        }

        return true;
    }

    public function isInstalled(): bool
    {
        return array_diff(array_keys(self::TRIGGERS), $this->existing()) === [];
    }

    public function remove(): void
    {
        foreach (array_keys(self::TRIGGERS) as $name) {
            DB::unprepared("DROP TRIGGER IF EXISTS {$name}");
        }
    }

    /**
     * @return list<string>
     */
    private function existing(): array
    {
        $names = match (DB::getDriverName()) {
            'sqlite' => DB::table('sqlite_master')->where('type', 'trigger')->where('tbl_name', 'activity_log')->pluck('name'),
            'mysql', 'mariadb' => DB::table('information_schema.TRIGGERS')
                ->whereRaw('TRIGGER_SCHEMA = DATABASE()')
                ->where('EVENT_OBJECT_TABLE', 'activity_log')
                ->pluck('TRIGGER_NAME'),
            default => collect(),
        };

        return $names->map(fn (mixed $name): string => (string) $name)->values()->all();
    }
}
