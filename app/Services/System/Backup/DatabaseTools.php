<?php

namespace App\Services\System\Backup;

use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;
use Throwable;

/**
 * Accès à la base pour la sauvegarde : copie du fichier SQLite, ou export/import MySQL ou MariaDB
 * par les outils en ligne de commande (mysqldump, mysql), sans jamais mettre le mot de passe sur la ligne de commande.
 */
class DatabaseTools
{
    public function connectionName(): string
    {
        return (string) config('database.default');
    }

    /**
     * @return array<string, mixed>
     */
    public function config(): array
    {
        /** @var array<string, mixed> */
        return config('database.connections.'.$this->connectionName(), []);
    }

    public function driver(): string
    {
        return (string) ($this->config()['driver'] ?? '');
    }

    public function isSqlite(): bool
    {
        return $this->driver() === 'sqlite';
    }

    public function isMysqlFamily(): bool
    {
        return in_array($this->driver(), ['mysql', 'mariadb'], true);
    }

    public function databaseName(): string
    {
        return (string) ($this->config()['database'] ?? '');
    }

    public function sqlitePath(): string
    {
        $path = $this->databaseName();

        if ($path === '' || $path === ':memory:' || str_contains($path, 'mode=memory')) {
            throw new BackupException('La base est en mémoire (SQLite) : il n\'y a aucun fichier à sauvegarder.');
        }

        $resolved = realpath($path) ?: realpath(base_path($path)) ?: realpath(database_path($path));

        if ($resolved === false) {
            throw new BackupException("Le fichier de la base SQLite est introuvable : {$path}.");
        }

        return $resolved;
    }

    /**
     * @param  'dump'|'client'  $kind
     */
    public function findBinary(string $kind, ?string $binDir = null): string
    {
        $names = $kind === 'dump' ? ['mysqldump', 'mariadb-dump'] : ['mysql', 'mariadb'];
        $dirs = [];

        foreach ([$binDir, getenv('PATRIMOINE_MYSQL_BIN') ?: null] as $explicit) {
            if ($explicit !== null && $explicit !== '') {
                $dirs[] = rtrim($explicit, '/\\');
            }
        }

        $wamp = array_merge(
            glob('C:/wamp64/bin/mariadb/*/bin', GLOB_ONLYDIR) ?: [],
            glob('C:/wamp64/bin/mysql/*/bin', GLOB_ONLYDIR) ?: [],
        );

        if (! $this->serverIsMariaDb()) {
            $wamp = array_reverse($wamp);
        }

        $dirs = [...$dirs, ...$wamp, ...array_filter(explode(PATH_SEPARATOR, (string) getenv('PATH')))];

        foreach ($dirs as $dir) {
            foreach ($names as $name) {
                foreach ([$name.'.exe', $name] as $file) {
                    $candidate = rtrim($dir, '/\\').DIRECTORY_SEPARATOR.$file;

                    if (is_file($candidate)) {
                        return $candidate;
                    }
                }
            }
        }

        throw new BackupException(
            'Outil '.($kind === 'dump' ? 'mysqldump' : 'mysql').' introuvable. Indiquez le dossier des outils MySQL ou MariaDB '
            .'avec --bin=C:\wamp64\bin\mariadb\...\bin (ou la variable PATRIMOINE_MYSQL_BIN).',
        );
    }

    private function serverIsMariaDb(): bool
    {
        try {
            return stripos((string) DB::selectOne('select version() as v')?->v, 'mariadb') !== false;
        } catch (Throwable) {
            return false;
        }
    }

    public function dump(string $targetFile, ?string $binDir = null): void
    {
        $bin = $this->findBinary('dump', $binDir);
        $cnf = $this->writeClientConfig();

        try {
            $process = new Process([
                $bin,
                '--defaults-extra-file='.$cnf,
                '--single-transaction',
                '--routines',
                '--triggers',
                '--no-tablespaces',
                '--default-character-set=utf8mb4',
                '--result-file='.$targetFile,
                $this->databaseName(),
            ]);
            $process->setTimeout(3600);
            $process->run();
        } finally {
            @unlink($cnf);
        }

        if (! $process->isSuccessful()) {
            throw new BackupException('L\'export de la base a échoué : '.trim($process->getErrorOutput() ?: $process->getOutput()));
        }

        if (! is_file($targetFile) || filesize($targetFile) === 0) {
            throw new BackupException('L\'export de la base est vide.');
        }

        $handle = fopen($targetFile, 'rb');
        fseek($handle, -min(512, (int) filesize($targetFile)), SEEK_END);
        $tail = (string) fread($handle, 512);
        fclose($handle);

        if (! str_contains($tail, 'Dump completed')) {
            throw new BackupException('L\'export de la base est incomplet (la ligne de fin est absente).');
        }
    }

    public function load(string $dumpFile, ?string $binDir = null): void
    {
        $bin = $this->findBinary('client', $binDir);
        $cnf = $this->writeClientConfig();
        $input = fopen($dumpFile, 'rb');

        try {
            $process = new Process([$bin, '--defaults-extra-file='.$cnf, '--default-character-set=utf8mb4', $this->databaseName()]);
            $process->setTimeout(3600);
            $process->setInput($input);
            $process->run();
        } finally {
            if (is_resource($input)) {
                fclose($input);
            }

            @unlink($cnf);
        }

        if (! $process->isSuccessful()) {
            throw new BackupException('L\'import de la base a échoué : '.trim($process->getErrorOutput() ?: $process->getOutput()));
        }
    }

    private function writeClientConfig(): string
    {
        $config = $this->config();
        $quote = fn (mixed $value): string => '"'.str_replace(['\\', '"'], ['\\\\', '\\"'], (string) $value).'"';

        $lines = [
            '[client]',
            'host='.$quote($config['host'] ?? '127.0.0.1'),
            'port='.(int) ($config['port'] ?? 3306),
            'user='.$quote($config['username'] ?? 'root'),
            'password='.$quote($config['password'] ?? ''),
        ];

        $file = tempnam(sys_get_temp_dir(), 'pat');

        if ($file === false) {
            throw new BackupException('Impossible de créer le fichier temporaire de connexion.');
        }

        chmod($file, 0600);
        file_put_contents($file, implode(PHP_EOL, $lines).PHP_EOL);

        return $file;
    }
}
