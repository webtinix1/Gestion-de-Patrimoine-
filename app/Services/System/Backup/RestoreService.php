<?php

namespace App\Services\System\Backup;

use FilesystemIterator;
use Illuminate\Support\Facades\DB;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use Throwable;

/**
 * Restauration d'une sauvegarde de `BackupService`. Rien n'est détruit : l'état courant est d'abord sauvegardé
 * à côté de la sauvegarde source, et les fichiers privés actuels sont renommés (jamais supprimés).
 */
class RestoreService
{
    public function __construct(
        private readonly BackupService $backups,
        private readonly DatabaseTools $database,
    ) {}

    /**
     * Nom de la base qui sera écrasée (à saisir pour confirmer).
     */
    public function targetName(): string
    {
        return $this->database->isSqlite() ? basename($this->database->sqlitePath()) : $this->database->databaseName();
    }

    /**
     * Vérifie la sauvegarde (intégrité, type de base) sans rien modifier.
     *
     * @return array<string, mixed>
     */
    public function inspect(string $source): array
    {
        $dir = realpath($source);

        if ($dir === false || ! is_dir($dir)) {
            throw new BackupException("Le dossier de sauvegarde est introuvable : {$source}.");
        }

        $errors = $this->backups->verify($dir);

        if ($errors !== []) {
            throw new BackupException('La sauvegarde est corrompue ou incomplète, rien n\'a été modifié : '.implode(' ; ', array_slice($errors, 0, 5)));
        }

        $manifest = $this->backups->manifest($dir);
        $driver = $this->database->isSqlite() ? 'sqlite' : 'mysql';

        if (($manifest['database']['driver'] ?? '') !== $driver) {
            throw new BackupException("Cette sauvegarde vient d'une base {$manifest['database']['driver']}, la base actuelle est {$driver} : restauration refusée.");
        }

        return ['path' => $dir] + $manifest;
    }

    /**
     * @return array{safety: string, previous_files: string|null}
     */
    public function restore(string $source, ?string $binDir = null): array
    {
        $manifest = $this->inspect($source);
        $dir = (string) $manifest['path'];

        // Filet de sécurité : l'état courant est sauvegardé avant toute écriture. S'il échoue, on s'arrête.
        $safety = $this->backups->create(dirname($dir), $binDir, 'avant-restauration');

        try {
            $this->restoreDatabase($dir, $manifest, $binDir);
        } catch (Throwable $e) {
            throw new BackupException(
                'La restauration de la base a échoué : '.$e->getMessage()." L'état précédent est conservé dans {$safety['path']}.",
                0,
                $e,
            );
        }

        $previous = $this->restoreFiles($dir);

        return ['safety' => $safety['path'], 'previous_files' => $previous];
    }

    /**
     * @param  array<string, mixed>  $manifest
     */
    private function restoreDatabase(string $dir, array $manifest, ?string $binDir): void
    {
        $file = $dir.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, (string) $manifest['database']['file']);

        if ($this->database->isSqlite()) {
            $target = $this->database->sqlitePath();
            DB::disconnect($this->database->connectionName());
            $temporary = $target.'.restauration';
            copy($file, $temporary);
            rename($temporary, $target);

            return;
        }

        $this->database->load($file, $binDir);
    }

    private function restoreFiles(string $dir): ?string
    {
        $root = $this->backups->privateRoot();
        $previous = null;

        if (is_dir($root)) {
            $previous = $root.'.avant-restauration-'.now()->format('Ymd-His');

            if (! rename($root, $previous)) {
                throw new BackupException("Impossible de mettre de côté les fichiers actuels ({$root}). La base a déjà été restaurée : fermez les programmes qui utilisent ces fichiers et recommencez.");
            }
        }

        mkdir($root, 0775, true);

        $source = $dir.DIRECTORY_SEPARATOR.'fichiers';
        $length = strlen($source) + 1;
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($source, FilesystemIterator::SKIP_DOTS));

        /** @var SplFileInfo $file */
        foreach ($iterator as $file) {
            if (! $file->isFile()) {
                continue;
            }

            $target = $root.DIRECTORY_SEPARATOR.substr($file->getPathname(), $length);

            if (! is_dir(dirname($target))) {
                mkdir(dirname($target), 0775, true);
            }

            copy($file->getPathname(), $target);
        }

        return $previous;
    }
}
