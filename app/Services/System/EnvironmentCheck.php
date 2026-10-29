<?php

namespace App\Services\System;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Vérifications de l'installation sur le serveur local : version de PHP, extensions (celles de composer.json
 * et celles dont dépendent Laravel, Filament et les exports), droits d'écriture, base joignable.
 * Chaque contrôle renvoie un statut `ok`, `warn` (à corriger sans bloquer) ou `fail` (installation impossible).
 */
class EnvironmentCheck
{
    public const string MINIMUM_PHP = '8.3.0';

    /**
     * Extensions exigées en plus de celles déclarées dans composer.json.
     *
     * @var list<string>
     */
    public const array EXTENSIONS = ['bcmath', 'ctype', 'curl', 'dom', 'fileinfo', 'gd', 'iconv', 'intl', 'mbstring', 'openssl', 'pdo', 'tokenizer', 'xml', 'zip'];

    /**
     * @return list<array{label: string, status: 'ok'|'warn'|'fail', detail: string}>
     */
    public function run(bool $withDatabase = true, bool $repair = false): array
    {
        $results = [$this->php(), ...$this->extensions(), ...$this->directories($repair), ...$this->limits(), $this->key(), $this->front()];

        if ($withDatabase) {
            $results[] = $database = $this->database();

            if ($database['status'] === 'ok') {
                $results[] = $this->activityLogProtection($repair);
            }
        }

        return $results;
    }

    /**
     * RG-70 : déclencheurs qui verrouillent le journal dans la base ; reposés avec --reparer.
     *
     * @return array{label: string, status: 'ok'|'warn'|'fail', detail: string}
     */
    public function activityLogProtection(bool $repair = false): array
    {
        $label = 'Journal verrouillé en base';
        $protection = app(ActivityLogProtection::class);

        try {
            if (! Schema::hasTable('activity_log')) {
                return ['label' => $label, 'status' => 'warn', 'detail' => 'tables absentes : lancez php artisan migrate'];
            }

            if ($protection->isInstalled() || ($repair && $protection->install())) {
                return ['label' => $label, 'status' => 'ok', 'detail' => 'modification et suppression refusées par la base'];
            }
        } catch (Throwable $e) {
            return ['label' => $label, 'status' => 'warn', 'detail' => 'contrôle impossible : '.$this->firstLine($e->getMessage())];
        }

        return ['label' => $label, 'status' => 'warn', 'detail' => 'protection absente (le journal reste protégé par l\'application). '.ActivityLogProtection::ADVICE];
    }

    /**
     * @param  list<array{label: string, status: string, detail: string}>  $results
     */
    public function hasFailure(array $results): bool
    {
        return collect($results)->contains(fn (array $result): bool => $result['status'] === 'fail');
    }

    /**
     * RG-66 : plus grande taille de fichier que PHP accepte (le plus petit de upload_max_filesize et post_max_size),
     * en Ko ; null quand PHP n'impose aucune limite.
     */
    public function uploadLimitKb(): ?int
    {
        $limits = [];

        foreach (['upload_max_filesize', 'post_max_size'] as $name) {
            $bytes = ini_parse_quantity((string) ini_get($name));

            if ($bytes > 0) {
                $limits[] = intdiv($bytes, 1024);
            }
        }

        return $limits === [] ? null : min($limits);
    }

    /**
     * @return array{label: string, status: 'ok'|'fail', detail: string}
     */
    public function php(): array
    {
        $ok = version_compare(PHP_VERSION, self::MINIMUM_PHP, '>=');

        return [
            'label' => 'Version de PHP',
            'status' => $ok ? 'ok' : 'fail',
            'detail' => $ok ? PHP_VERSION : PHP_VERSION.' : la version '.self::MINIMUM_PHP.' ou plus récente est exigée.',
        ];
    }

    /**
     * @return list<string>
     */
    public function requiredExtensions(): array
    {
        $required = self::EXTENSIONS;
        $composer = base_path('composer.json');

        if (is_file($composer)) {
            $require = (array) (json_decode((string) file_get_contents($composer), true)['require'] ?? []);

            foreach (array_keys($require) as $package) {
                if (str_starts_with((string) $package, 'ext-')) {
                    $required[] = substr((string) $package, 4);
                }
            }
        }

        $driver = (string) config('database.connections.'.config('database.default').'.driver');

        if (in_array($driver, ['mysql', 'mariadb'], true)) {
            $required[] = 'pdo_mysql';
        } elseif ($driver === 'sqlite') {
            $required[] = 'pdo_sqlite';
        } elseif ($driver === 'pgsql') {
            $required[] = 'pdo_pgsql';
        }

        $required = array_values(array_unique($required));
        sort($required);

        return $required;
    }

    /**
     * @return list<array{label: string, status: 'ok'|'fail', detail: string}>
     */
    public function extensions(): array
    {
        $missing = array_values(array_filter($this->requiredExtensions(), fn (string $name): bool => ! extension_loaded($name)));

        if ($missing === []) {
            return [['label' => 'Extensions PHP', 'status' => 'ok', 'detail' => count($this->requiredExtensions()).' extensions présentes']];
        }

        return [[
            'label' => 'Extensions PHP',
            'status' => 'fail',
            'detail' => 'manquantes : '.implode(', ', $missing).'. Activez-les dans php.ini (extension=...) puis redémarrez.',
        ]];
    }

    /**
     * @return list<array{label: string, status: 'ok'|'fail', detail: string}>
     */
    public function directories(bool $repair = false): array
    {
        $results = [];

        foreach (['storage', 'storage/app/private', 'storage/framework', 'storage/logs', 'bootstrap/cache'] as $relative) {
            $path = base_path($relative);

            // M10 : sans $repair (patrimoine:verifier), rien n'est créé ni écrit.
            if (! is_dir($path) && ! ($repair && @mkdir($path, 0775, true))) {
                $results[] = ['label' => "Dossier {$relative}", 'status' => 'fail', 'detail' => $repair ? 'absent et impossible à créer' : 'absent : relancez install.ps1'];

                continue;
            }

            if ($repair) {
                $probe = $path.DIRECTORY_SEPARATOR.'.ecriture-'.bin2hex(random_bytes(3));
                $writable = @file_put_contents($probe, 'x') !== false;
                @unlink($probe);
            } else {
                $writable = is_writable($path);
            }

            $results[] = [
                'label' => "Dossier {$relative}",
                'status' => $writable ? 'ok' : 'fail',
                'detail' => $writable ? 'accessible en écriture' : 'non accessible en écriture : donnez les droits de modification à l\'utilisateur du serveur web',
            ];
        }

        return $results;
    }

    /**
     * RG-66 : documents de 20 Mo. Ces valeurs sont celles de PHP en ligne de commande ; le serveur web
     * a son propre php.ini (WAMP : menu PHP > php.ini), à régler de la même façon.
     *
     * @return list<array{label: string, status: 'ok'|'warn', detail: string}>
     */
    public function limits(): array
    {
        $results = [];

        foreach ([['upload_max_filesize', '25M'], ['post_max_size', '30M'], ['memory_limit', '256M']] as [$name, $minimum]) {
            $value = (string) ini_get($name);
            $bytes = ini_parse_quantity($value);
            $ok = $bytes === 0 || $bytes === -1 || $bytes >= ini_parse_quantity($minimum);

            $results[] = [
                'label' => "PHP : {$name}",
                'status' => $ok ? 'ok' : 'warn',
                'detail' => $ok ? $value : "{$value} (au moins {$minimum} recommandé ; php.ini du serveur web)",
            ];
        }

        return $results;
    }

    /**
     * @return array{label: string, status: 'ok'|'fail', detail: string}
     */
    public function key(): array
    {
        $ok = (string) config('app.key') !== '';

        return [
            'label' => 'Clé de l\'application',
            'status' => $ok ? 'ok' : 'fail',
            'detail' => $ok ? 'définie' : 'APP_KEY est vide : lancez install.ps1 (ou php artisan key:generate)',
        ];
    }

    /**
     * @return array{label: string, status: 'ok'|'warn', detail: string}
     */
    public function front(): array
    {
        $ok = is_file(public_path('build/manifest.json'));

        return [
            'label' => 'Interface compilée (public/build)',
            'status' => $ok ? 'ok' : 'warn',
            'detail' => $ok ? 'présente' : 'absente : npm ci puis npm run build (Node 20.19+ ou 22+), ou utilisez le paquet livré',
        ];
    }

    /**
     * @return array{label: string, status: 'ok'|'fail', detail: string}
     */
    public function database(): array
    {
        $connection = (string) config('database.default');
        $driver = (string) config("database.connections.{$connection}.driver");

        try {
            DB::connection()->getPdo();
            $detail = $driver.' joignable';

            if (in_array($driver, ['mysql', 'mariadb'], true)) {
                $detail .= ', version '.DB::selectOne('select version() as v')->v;
            }

            return ['label' => 'Base de données', 'status' => 'ok', 'detail' => $detail];
        } catch (Throwable $e) {
            return [
                'label' => 'Base de données',
                'status' => 'fail',
                'detail' => $driver.' injoignable : '.$this->firstLine($e->getMessage()).'. Vérifiez que MySQL ou MariaDB est démarré et les valeurs DB_* du fichier .env.',
            ];
        }
    }

    private function firstLine(string $message): string
    {
        return trim(strtok($message, "\n") ?: $message);
    }
}
