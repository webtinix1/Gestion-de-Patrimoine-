<?php

namespace App\Console\Commands;

use App\Services\System\Backup\BackupException;
use App\Services\System\Backup\RestoreService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Restaure une sauvegarde de `patrimoine:sauvegarder`. Écrase la base et les fichiers privés :
 * confirmation explicite obligatoire, refus en production sans --force, état courant mis de côté.
 */
#[Signature('patrimoine:restaurer {source : Dossier de la sauvegarde (patrimoine-AAAAMMJJ-HHMMSS)} {--base= : Nom de la base à écraser, pour confirmer sans interaction} {--force : Autorise la restauration en production} {--bin= : Dossier des outils MySQL ou MariaDB}')]
#[Description('Restaure la base et les fichiers privés depuis une sauvegarde (écrase les données actuelles)')]
class RestoreCommand extends Command
{
    public function handle(RestoreService $restore): int
    {
        if (! $this->laravel->runningInConsole()) {
            $this->error('Cette commande est réservée à la ligne de commande (php artisan).');

            return self::FAILURE;
        }

        if (! $this->laravel->isDownForMaintenance()) {
            $this->error('Restauration refusée : l\'application doit être en maintenance pour que personne n\'écrive pendant l\'opération. Lancez php artisan down, puis relancez cette commande.');

            return self::FAILURE;
        }

        if ($this->laravel->isProduction() && ! $this->option('force')) {
            $this->error('Restauration refusée en production sans l\'option --force. Arrêtez l\'application (php artisan down), faites relire la procédure de docs/07-deploiement-local.md, puis relancez avec --force.');

            return self::FAILURE;
        }

        try {
            $manifest = $restore->inspect((string) $this->argument('source'));
            $target = $restore->targetName();

            $this->warn("Cette opération REMPLACE la base « {$target} » et les fichiers privés par la sauvegarde du ".$manifest['created_at'].'.');
            $this->line('Sauvegarde contrôlée : '.count($manifest['files']).' fichier(s). L\'état actuel sera d\'abord sauvegardé à côté de la source.');

            $given = $this->option('base');

            if ($given === null) {
                if (! $this->input->isInteractive()) {
                    $this->error('Confirmation absente : passez --base='.$target.' pour confirmer sans interaction.');

                    return self::FAILURE;
                }

                $given = $this->ask("Pour confirmer, tapez le nom de la base à écraser ({$target})");
            }

            if ($given !== $target) {
                $this->error('Confirmation incorrecte : rien n\'a été modifié.');

                return self::FAILURE;
            }

            $result = $restore->restore((string) $this->argument('source'), $this->option('bin') ?: null);
        } catch (BackupException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info('Restauration terminée.');
        $this->line('État précédent sauvegardé dans : '.$result['safety']);

        if ($result['previous_files'] !== null) {
            $this->line('Anciens fichiers privés conservés dans : '.$result['previous_files']);
        }

        $this->line('Terminez par : php artisan optimize:clear, php artisan optimize, puis php artisan up.');

        return self::SUCCESS;
    }
}
