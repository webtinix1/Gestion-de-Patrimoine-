<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * RG-70 : le journal des opérations ne se modifie ni ne se supprime, même hors de l'application
 * (requête directe, commande activitylog:clean). Le modèle Activity le refuse déjà ; les déclencheurs
 * l'imposent dans la base elle-même.
 */
return new class extends Migration
{
    private const array TRIGGERS = [
        'activity_log_no_update' => 'UPDATE',
        'activity_log_no_delete' => 'DELETE',
    ];

    private const string MESSAGE = 'Le journal des opérations ne peut être ni modifié ni supprimé.';

    public function up(): void
    {
        $driver = DB::getDriverName();

        foreach (self::TRIGGERS as $name => $event) {
            match ($driver) {
                'sqlite' => DB::unprepared("CREATE TRIGGER {$name} BEFORE {$event} ON activity_log BEGIN SELECT RAISE(ABORT, '".self::MESSAGE."'); END"),
                'mysql', 'mariadb' => DB::unprepared("CREATE TRIGGER {$name} BEFORE {$event} ON activity_log FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = '".self::MESSAGE."'"),
                default => null,
            };
        }
    }

    public function down(): void
    {
        foreach (array_keys(self::TRIGGERS) as $name) {
            DB::unprepared("DROP TRIGGER IF EXISTS {$name}");
        }
    }
};
