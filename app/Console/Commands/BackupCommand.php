<?php

namespace App\Console\Commands;

use App\Services\System\Backup\BackupException;
use App\Services\System\Backup\BackupService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Q20 : sauvegarde manuelle (option) de la base et des fichiers privés vers un dossier daté.
 */
#[Signature('patrimoine:sauvegarder {destination : Dossier existant qui recevra la sauvegarde (disque externe, partage réseau)} {--bin= : Dossier des outils MySQL ou MariaDB (mysqldump), détecté dans WAMP sinon}')]
#[Description('Sauvegarde la base et les fichiers privés dans un dossier daté, avec contrôle d\'intégrité')]
class BackupCommand extends Command
{
    public function handle(BackupService $backups): int
    {
        if (! $this->laravel->runningInConsole()) {
            return self::FAILURE;
        }

        $started = microtime(true);

        try {
            $result = $backups->create((string) $this->argument('destination'), $this->option('bin') ?: null);
        } catch (BackupException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->table(['Élément', 'Contenu'], [
            ['Dossier', $result['path']],
            ['Base', $result['database_file'].' ('.number_format($result['database_bytes'], 0, ',', ' ').' octets)'],
            ['Fichiers privés', $result['files'].' fichier(s), '.number_format($result['files_bytes'], 0, ',', ' ').' octets'],
            ['Durée', round(microtime(true) - $started, 1).' s'],
        ]);

        $this->info('Sauvegarde terminée et contrôlée (taille et somme SHA-256 de chaque fichier). Pensez à conserver aussi une copie du fichier .env.');

        return self::SUCCESS;
    }
}
