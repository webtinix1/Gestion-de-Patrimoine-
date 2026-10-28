<?php

namespace App\Services\System\Backup;

use FilesystemIterator;
use Illuminate\Support\Facades\DB;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Throwable;

/**
 * Restauration d'une sauvegarde de `BackupService`. Rien n'est détruit : l'état courant est d'abord sauvegardé
 * à côté de la sauvegarde source, les fichiers privés actuels sont renommés (jamais supprimés), les nouveaux
 * sont préparés dans un dossier de transit avant tout échange, et si la base échoue l'état précédent est remis.
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
     * Vérifie la sauvegarde (intégrité, fichiers en trop, type de base) sans rien modifier.
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

        $root = $this->normalize($this->backups->privateRoot());
        $stamp = now()->format('Ymd-His');
        $transit = $root.'.transit-'.$stamp;

        try {
            $this->prepareTransit($dir, $manifest, $transit);
            $previous = $this->swapFiles($root, $transit, $stamp);
        } catch (Throwable $e) {
            $this->removeTree($transit);

            throw $e instanceof BackupException ? $e : new BackupException('Préparation des fichiers impossible : '.$e->getMessage(), 0, $e);
        }

        try {
            $this->restoreDatabase($dir, $manifest, $binDir);
        } catch (Throwable $e) {
            $this->rollback($root, $previous, $stamp);

            throw new BackupException($this->failureMessage($e, $safety['path'], $binDir), 0, $e);
        }

        return ['safety' => $safety['path'], 'previous_files' => $previous];
    }

    /**
     * Copie, en les recontrôlant, les seuls fichiers listés au manifeste dans un dossier de transit.
     *
     * @param  array<string, mixed>  $manifest
     */
    private function prepareTransit(string $dir, array $manifest, string $transit): void
    {
        if (file_exists($transit)) {
            throw new BackupException("Le dossier de transit existe déjà : {$transit}.");
        }

        mkdir($transit, 0775, true);

        foreach ($manifest['files'] as $entry) {
            $relative = substr((string) $entry['path'], strlen('fichiers/'));
            $source = $dir.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, (string) $entry['path']);
            $target = $transit.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $relative);

            if (! is_dir(dirname($target))) {
                mkdir(dirname($target), 0775, true);
            }

            if (! copy($source, $target) || hash_file('sha256', $target) !== $entry['sha256']) {
                throw new BackupException("Copie des fichiers impossible ou altérée : {$relative}. Rien n'a été modifié.");
            }
        }
    }

    private function swapFiles(string $root, string $transit, string $stamp): ?string
    {
        $previous = null;

        if (is_dir($root)) {
            $previous = $root.'.avant-restauration-'.$stamp;

            if (! @rename($root, $previous)) {
                throw new BackupException("Impossible de mettre de côté les fichiers actuels ({$root}) : fermez les programmes qui les utilisent et recommencez. Rien n'a été modifié.");
            }
        }

        if (! @rename($transit, $root)) {
            if ($previous !== null) {
                @rename($previous, $root);
            }

            throw new BackupException("Impossible de mettre en place les fichiers restaurés ({$root}). Les fichiers actuels ont été remis en place.");
        }

        return $previous;
    }

    private function rollback(string $root, ?string $previous, string $stamp): void
    {
        if ($previous === null) {
            return;
        }

        if (is_dir($root)) {
            @rename($root, $root.'.echec-restauration-'.$stamp);
        }

        @rename($previous, $root);
    }

    private function failureMessage(Throwable $e, string $safetyPath, ?string $binDir): string
    {
        $base = 'La restauration de la base a échoué : '.$e->getMessage().' ';

        try {
            $manifest = $this->backups->manifest($safetyPath);
            $this->restoreDatabase($safetyPath, $manifest, $binDir);

            return $base.'L\'état précédent (base et fichiers) a été remis en place automatiquement.';
        } catch (Throwable) {
            return $base."Base partiellement restaurée, fichiers précédents remis en place : relancez php artisan patrimoine:restaurer \"{$safetyPath}\" --base=".$this->targetName().' pour revenir à l\'état précédent.';
        }
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
            @unlink($temporary);

            if (! copy($file, $temporary)) {
                throw new BackupException('Copie du fichier de base impossible.');
            }

            // M4 : les journaux de l'ancienne base ne doivent pas être rejoués sur la base restaurée.
            foreach (['-journal', '-wal', '-shm'] as $suffix) {
                @unlink($target.$suffix);
            }

            if (! @rename($temporary, $target)) {
                @unlink($temporary);

                throw new BackupException('Remplacement du fichier de base impossible (fichier utilisé par un autre programme ?).');
            }

            return;
        }

        $this->database->load($file, $binDir);
    }

    private function normalize(string $path): string
    {
        return rtrim(str_replace(['/', '\\'], DIRECTORY_SEPARATOR, realpath($path) ?: $path), DIRECTORY_SEPARATOR);
    }

    private function removeTree(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($iterator as $item) {
            $item->isDir() && ! $item->isLink() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
        }

        @rmdir($dir);
    }
}
