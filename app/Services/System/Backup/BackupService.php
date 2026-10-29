<?php

namespace App\Services\System\Backup;

use App\Services\Settings;
use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use Throwable;

/**
 * Q20 : sauvegarde manuelle. Un dossier daté contient la base (`base/`), les fichiers privés (`fichiers/`)
 * et `manifest.json`, qui liste la taille et la somme SHA-256 de chaque fichier. La sauvegarde est relue
 * et contrôlée avant d'être déclarée réussie ; en cas d'échec, le dossier partiel est retiré.
 */
class BackupService
{
    public const int MANIFEST_VERSION = 1;

    // Signature HMAC-SHA256 de manifest.json avec APP_KEY : le manifeste porte les SHA-256 de tout le reste.
    public const string SIGNATURE_FILE = 'manifest.sig';

    public function __construct(private readonly DatabaseTools $database) {}

    public function privateRoot(): string
    {
        return (string) config('filesystems.disks.local.root', storage_path('app/private'));
    }

    /**
     * @return array{path: string, database_file: string, database_bytes: int, files: int, files_bytes: int}
     */
    public function create(string $destination, ?string $binDir = null, string $prefix = 'patrimoine'): array
    {
        $root = $this->checkDestination($destination);
        $dir = $root.DIRECTORY_SEPARATOR.$prefix.'-'.now()->format('Ymd-His');

        if (file_exists($dir)) {
            throw new BackupException("Le dossier {$dir} existe déjà : relancez la commande dans une seconde.");
        }

        mkdir($dir.DIRECTORY_SEPARATOR.'base', 0775, true);
        mkdir($dir.DIRECTORY_SEPARATOR.'fichiers', 0775, true);

        try {
            $database = $this->saveDatabase($dir, $binDir);
            $files = $this->copyFiles($dir);

            $manifest = [
                'version' => self::MANIFEST_VERSION,
                'application' => (string) config('app.name'),
                'created_at' => now()->toIso8601String(),
                'database' => $database,
                'files' => $files,
            ];

            file_put_contents(
                $dir.DIRECTORY_SEPARATOR.'manifest.json',
                json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            );

            file_put_contents($dir.DIRECTORY_SEPARATOR.self::SIGNATURE_FILE, $this->signature($dir));

            $errors = $this->verify($dir);

            if ($errors !== [] || ! $this->verifySignature($dir)) {
                $errors[] = 'signature du manifeste invalide';
                throw new BackupException('Le contrôle d\'intégrité de la sauvegarde a échoué : '.implode(' ; ', array_slice($errors, 0, 5)));
            }
        } catch (Throwable $e) {
            $this->removeTree($dir);

            throw $e instanceof BackupException ? $e : new BackupException('La sauvegarde a échoué : '.$e->getMessage(), 0, $e);
        }

        try {
            app(Settings::class)->recordTechnical(Settings::LAST_BACKUP_AT, now()->toIso8601String());
        } catch (Throwable) {
            // L'état affiché par E41 ne doit jamais faire échouer une sauvegarde déjà contrôlée.
        }

        return [
            'path' => $dir,
            'database_file' => $database['file'],
            'database_bytes' => $database['size'],
            'files' => count($files),
            'files_bytes' => (int) array_sum(array_column($files, 'size')),
        ];
    }

    /**
     * Relit chaque fichier de la sauvegarde et le compare au manifeste ; tout fichier absent du manifeste
     * (dans `base/` ou `fichiers/`) est signalé.
     *
     * @return list<string> erreurs ; vide si la sauvegarde est intègre
     */
    public function verify(string $dir): array
    {
        $manifestPath = $dir.DIRECTORY_SEPARATOR.'manifest.json';

        if (! is_file($manifestPath)) {
            return ['manifest.json est absent : ce dossier n\'est pas une sauvegarde de l\'application.'];
        }

        $manifest = json_decode((string) file_get_contents($manifestPath), true);

        if (! is_array($manifest) || ($manifest['version'] ?? null) !== self::MANIFEST_VERSION
            || ! is_array($manifest['database'] ?? null) || ! is_array($manifest['files'] ?? null)) {
            return ['manifest.json est illisible ou d\'une version inconnue.'];
        }

        $driver = $manifest['database']['driver'] ?? null;
        $expectedDatabaseFile = ['sqlite' => 'base/database.sqlite', 'mysql' => 'base/dump.sql'][$driver] ?? null;

        if ($expectedDatabaseFile === null || ($manifest['database']['file'] ?? null) !== $expectedDatabaseFile) {
            return ['manifest.json : type ou fichier de base inattendu.'];
        }

        $errors = [];
        $entries = [['path' => $expectedDatabaseFile, 'size' => $manifest['database']['size'] ?? null, 'sha256' => $manifest['database']['sha256'] ?? null]];

        foreach ($manifest['files'] as $entry) {
            if (! is_array($entry) || ! is_string($entry['path'] ?? null) || ! is_int($entry['size'] ?? null) || ! is_string($entry['sha256'] ?? null)
                || ! str_starts_with($entry['path'], 'fichiers/')) {
                return ['manifest.json : une entrée de fichier est mal formée.'];
            }

            $entries[] = $entry;
        }

        $known = [];

        foreach ($entries as $entry) {
            $relative = (string) $entry['path'];

            if (! self::isSafeRelativePath($relative)) {
                $errors[] = "chemin refusé : {$relative}";

                continue;
            }

            $known[$relative] = true;
            $path = $dir.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $relative);

            if (! is_file($path)) {
                $errors[] = "fichier manquant : {$relative}";
            } elseif (filesize($path) !== $entry['size']) {
                $errors[] = "taille différente : {$relative}";
            } elseif (hash_file('sha256', $path) !== $entry['sha256']) {
                $errors[] = "somme de contrôle différente : {$relative}";
            }
        }

        foreach (['base', 'fichiers'] as $folder) {
            if (! is_dir($dir.DIRECTORY_SEPARATOR.$folder)) {
                continue;
            }

            $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir.DIRECTORY_SEPARATOR.$folder, FilesystemIterator::SKIP_DOTS));

            /** @var SplFileInfo $file */
            foreach ($iterator as $file) {
                $relative = $folder.'/'.str_replace('\\', '/', substr($file->getPathname(), strlen($dir.DIRECTORY_SEPARATOR.$folder) + 1));

                if ($file->isFile() && ! isset($known[$relative])) {
                    $errors[] = "fichier en trop (absent du manifeste) : {$relative}";
                }
            }
        }

        return $errors;
    }

    /**
     * La signature (HMAC-SHA256 de manifest.json) est présente et correspond à l'APP_KEY de ce poste.
     */
    public function verifySignature(string $dir): bool
    {
        $path = $dir.DIRECTORY_SEPARATOR.self::SIGNATURE_FILE;

        if (! is_file($path) || ! is_file($dir.DIRECTORY_SEPARATOR.'manifest.json')) {
            return false;
        }

        return hash_equals($this->signature($dir), trim((string) file_get_contents($path)));
    }

    private function signature(string $dir): string
    {
        $key = (string) config('app.key');

        if ($key === '') {
            throw new BackupException('APP_KEY est vide : impossible de signer ou de vérifier le manifeste.');
        }

        return hash_hmac('sha256', (string) file_get_contents($dir.DIRECTORY_SEPARATOR.'manifest.json'), $key);
    }

    /**
     * Refuse chemin absolu, lettre de lecteur, antislash et segment exactement « .. » ; « devis..final.pdf » reste valide.
     */
    public static function isSafeRelativePath(string $path): bool
    {
        if ($path === '' || str_starts_with($path, '/') || str_contains($path, '\\') || str_contains($path, "\0")
            || preg_match('/^[A-Za-z]:/', $path) === 1) {
            return false;
        }

        foreach (explode('/', $path) as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..') {
                return false;
            }
        }

        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function manifest(string $dir): array
    {
        /** @var array<string, mixed> */
        return json_decode((string) file_get_contents($dir.DIRECTORY_SEPARATOR.'manifest.json'), true, flags: JSON_THROW_ON_ERROR);
    }

    private function checkDestination(string $destination): string
    {
        $root = $destination === '' ? false : realpath($destination);

        if ($root === false || ! is_dir($root)) {
            throw new BackupException("Le dossier de destination n'existe pas ou n'est pas accessible : {$destination}.");
        }

        $probe = $root.DIRECTORY_SEPARATOR.'.patrimoine-ecriture-'.bin2hex(random_bytes(4));

        if (@file_put_contents($probe, 'test') === false) {
            throw new BackupException("Le dossier de destination n'est pas accessible en écriture : {$root}.");
        }

        @unlink($probe);

        foreach ([base_path(), public_path(), $this->privateRoot()] as $forbidden) {
            $forbidden = realpath($forbidden) ?: $forbidden;

            if ($this->isInside($root, $forbidden)) {
                throw new BackupException('La destination ne peut pas se trouver dans le dossier de l\'application (public, fichiers privés ou autre). Choisissez un disque externe.');
            }
        }

        return $root;
    }

    private function isInside(string $path, string $container): bool
    {
        $path = rtrim(str_replace('\\', '/', strtolower($path)), '/').'/';
        $container = rtrim(str_replace('\\', '/', strtolower($container)), '/').'/';

        return str_starts_with($path, $container);
    }

    /**
     * @return array{driver: string, name: string, file: string, size: int, sha256: string}
     */
    private function saveDatabase(string $dir, ?string $binDir): array
    {
        if ($this->database->isSqlite()) {
            $relative = 'base/database.sqlite';
            $this->database->snapshotSqlite($dir.'/'.$relative);
            $name = basename($this->database->sqlitePath());
        } elseif ($this->database->isMysqlFamily()) {
            $relative = 'base/dump.sql';
            $this->database->dump($dir.'/'.$relative, $binDir);
            $name = $this->database->databaseName();
        } else {
            throw new BackupException('Pilote de base non pris en charge par la sauvegarde : '.$this->database->driver().'.');
        }

        return [
            'driver' => $this->database->isSqlite() ? 'sqlite' : 'mysql',
            'name' => $name,
            'file' => $relative,
            'size' => (int) filesize($dir.'/'.$relative),
            'sha256' => (string) hash_file('sha256', $dir.'/'.$relative),
        ];
    }

    /**
     * @return list<array{path: string, size: int, sha256: string}>
     */
    private function copyFiles(string $dir): array
    {
        $source = $this->privateRoot();
        $entries = [];

        if (! is_dir($source)) {
            return $entries;
        }

        $length = strlen(rtrim($source, '/\\')) + 1;
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($source, FilesystemIterator::SKIP_DOTS));

        /** @var SplFileInfo $file */
        foreach ($iterator as $file) {
            if ($file->isLink() || ! $file->isFile()) {
                continue;
            }

            $relative = str_replace('\\', '/', substr($file->getPathname(), $length));
            $target = $dir.'/fichiers/'.$relative;

            if (! is_dir(dirname($target))) {
                mkdir(dirname($target), 0775, true);
            }

            if (! copy($file->getPathname(), $target)) {
                throw new BackupException("Copie impossible : {$relative}.");
            }

            $entries[] = [
                'path' => 'fichiers/'.$relative,
                'size' => (int) $file->getSize(),
                'sha256' => (string) hash_file('sha256', $file->getPathname()),
            ];
        }

        return $entries;
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
