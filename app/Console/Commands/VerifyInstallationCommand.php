<?php

namespace App\Console\Commands;

use App\Services\System\EnvironmentCheck;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Contrôle l'installation (PHP, extensions, droits, base) sans rien modifier, à lancer à tout moment.
 */
#[Signature('patrimoine:verifier {--sans-base : Ne teste pas la connexion à la base}')]
#[Description('Vérifie l\'installation : version de PHP, extensions, dossiers, clé, base de données')]
class VerifyInstallationCommand extends Command
{
    public function handle(EnvironmentCheck $check): int
    {
        $results = $check->run(withDatabase: ! $this->option('sans-base'));

        $this->table(['Contrôle', 'Résultat', 'Détail'], array_map(fn (array $result): array => [
            $result['label'],
            ['ok' => 'OK', 'warn' => 'À corriger', 'fail' => 'BLOQUANT'][$result['status']],
            $result['detail'],
        ], $results));

        if ($check->hasFailure($results)) {
            $this->error('Au moins un contrôle bloquant a échoué : corrigez-le puis relancez.');

            return self::FAILURE;
        }

        $this->info('Installation conforme.');

        return self::SUCCESS;
    }
}
