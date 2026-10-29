<?php

namespace App\Console\Commands;

use App\Models\Asset;
use App\Models\Department;
use App\Models\Site;
use App\Models\User;
use App\Services\System\EnvironmentCheck;
use App\Support\DefaultRoles;
use Database\Seeders\DemoSeeder;
use Database\Seeders\ReferenceSeeder;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;
use PDO;
use Throwable;

/**
 * Installation sur le serveur local (appelée par install.ps1, relançable sans dégât) : contrôles, base,
 * migrations, référentiels de base, premier administrateur. Le mot de passe est saisi sans écho
 * (ou lu sur l'entrée standard), jamais passé en argument, jamais affiché ni journalisé.
 */
#[Signature('patrimoine:installer
    {--demo : Charge aussi la démonstration (comptes à mot de passe connu : jamais sur un serveur réel)}
    {--identifiant= : Identifiant du premier administrateur}
    {--prenom= : Prénom du premier administrateur}
    {--nom= : Nom du premier administrateur}
    {--mot-de-passe-stdin : Lit le mot de passe sur la première ligne de l\'entrée standard (scripts)}')]
#[Description('Installe l\'application : contrôles, base, migrations, référentiels, premier administrateur')]
class InstallCommand extends Command
{
    private const int MINIMUM_PASSWORD_LENGTH = 12;

    /**
     * Flux lu par --mot-de-passe-stdin à la place de l'entrée standard (tests).
     *
     * @var resource|null
     */
    public static $stdin = null;

    public function handle(EnvironmentCheck $check): int
    {
        if (! $this->laravel->runningInConsole()) {
            $this->error('Cette commande est réservée à la ligne de commande (php artisan).');

            return self::FAILURE;
        }

        $demo = (bool) $this->option('demo');

        if ($demo && $this->laravel->isProduction()) {
            $this->error('La démonstration crée des comptes dont le mot de passe est connu : refusée quand APP_ENV vaut production (aucune option ne l\'autorise).');

            return self::FAILURE;
        }

        $this->components->info('1/5 Contrôle de l\'environnement');
        $results = $check->run(withDatabase: false, repair: true);
        $this->printChecks($results);

        if ($check->hasFailure($results)) {
            $this->error('Installation interrompue : corrigez les contrôles bloquants puis relancez install.ps1.');

            return self::FAILURE;
        }

        $this->components->info('2/5 Base de données');

        if (! $this->ensureDatabase()) {
            return self::FAILURE;
        }

        $this->components->info('3/5 Tables (migrations)');
        $this->call('migrate', ['--force' => true]);

        // RG-70 : déclencheurs du journal refusés par MySQL faute de privilège : on prévient sans bloquer.
        $protection = $check->activityLogProtection();

        if ($protection['status'] !== 'ok') {
            $this->warn('Journal verrouillé en base : '.$protection['detail']);
        }

        $this->components->info('4/5 Référentiels de base');
        $this->call('db:seed', ['--class' => ReferenceSeeder::class, '--force' => true]);

        $this->components->info('5/5 Premier administrateur');

        if ($demo) {
            if (! $this->canLoadDemo()) {
                return self::FAILURE;
            }

            $this->call('db:seed', ['--class' => DemoSeeder::class, '--force' => true]);
            $this->warn('Démonstration chargée : les comptes de démonstration ont un mot de passe connu (voir docs/07-deploiement-local.md). Ne gardez pas cette base en service réel.');
        } elseif (! $this->ensureAdministrator()) {
            return self::FAILURE;
        }

        $this->newLine();
        $this->info('Installation terminée. Ouvrez l\'adresse de l\'application dans un navigateur et connectez-vous.');

        return self::SUCCESS;
    }

    /**
     * @param  list<array{label: string, status: string, detail: string}>  $results
     */
    private function printChecks(array $results): void
    {
        $this->table(['Contrôle', 'Résultat', 'Détail'], array_map(fn (array $result): array => [
            $result['label'],
            ['ok' => 'OK', 'warn' => 'À corriger', 'fail' => 'BLOQUANT'][$result['status']],
            $result['detail'],
        ], $results));
    }

    private function ensureDatabase(): bool
    {
        $connection = (string) config('database.default');
        $config = (array) config("database.connections.{$connection}");
        $driver = (string) ($config['driver'] ?? '');

        if ($driver === 'sqlite') {
            $path = (string) ($config['database'] ?? '');

            if ($path !== '' && $path !== ':memory:' && ! is_file($path) && ! is_file(base_path($path))) {
                touch(str_starts_with($path, '/') || preg_match('/^[A-Za-z]:[\\\\\/]/', $path) === 1 ? $path : base_path($path));
            }
        }

        try {
            DB::connection()->getPdo();
        } catch (Throwable $e) {
            if (! in_array($driver, ['mysql', 'mariadb'], true) || ! str_contains($e->getMessage(), '1049')) {
                $this->error('Base de données injoignable : '.trim(strtok($e->getMessage(), "\n") ?: $e->getMessage()));
                $this->line('Vérifiez que MySQL ou MariaDB est démarré et les valeurs DB_* du fichier .env.');

                return false;
            }

            if (! $this->createMysqlDatabase($config)) {
                return false;
            }

            DB::purge($connection);
        }

        $this->line('Base joignable ('.$driver.').');

        return true;
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private function createMysqlDatabase(array $config): bool
    {
        $name = (string) ($config['database'] ?? '');

        if (preg_match('/^[A-Za-z0-9_$-]{1,64}$/', $name) !== 1) {
            $this->error('Nom de base invalide dans DB_DATABASE : lettres, chiffres, tiret et soulignement uniquement.');

            return false;
        }

        try {
            $pdo = new PDO(
                sprintf('mysql:host=%s;port=%d', $config['host'] ?? '127.0.0.1', (int) ($config['port'] ?? 3306)),
                (string) ($config['username'] ?? 'root'),
                (string) ($config['password'] ?? ''),
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
            );
            $charset = preg_replace('/[^a-z0-9_]/i', '', (string) ($config['charset'] ?? 'utf8mb4'));
            $collation = preg_replace('/[^a-z0-9_]/i', '', (string) ($config['collation'] ?? 'utf8mb4_unicode_ci'));
            $pdo->exec("CREATE DATABASE IF NOT EXISTS `{$name}` CHARACTER SET {$charset} COLLATE {$collation}");
        } catch (Throwable $e) {
            $this->error('Création de la base impossible : '.$e->getMessage());

            return false;
        }

        $this->line("Base « {$name} » créée.");

        return true;
    }

    private function canLoadDemo(): bool
    {
        // DemoSeeder fait un updateOrCreate sur « admin » : sur une base non vide, il écraserait un vrai compte.
        $notEmpty = User::query()->exists() || Site::query()->withoutGlobalScopes()->exists()
            || Department::query()->withoutGlobalScopes()->exists() || Asset::query()->withoutGlobalScopes()->exists();

        if ($notEmpty) {
            $this->error('Démonstration refusée : la base contient déjà des comptes ou des données. Utilisez une base vide (php artisan migrate:fresh sur un poste d\'essai).');
        }

        return ! $notEmpty;
    }

    private function ensureAdministrator(): bool
    {
        $existing = User::query()->role(DefaultRoles::ADMIN)->count();

        if ($existing > 0) {
            $this->line('Un administrateur existe déjà : aucun compte créé (installation relancée sans dégât).');

            return true;
        }

        $stdin = (bool) $this->option('mot-de-passe-stdin');

        if (! $stdin && ! $this->input->isInteractive()) {
            $this->error('Aucun administrateur et aucune saisie possible : relancez en interactif, ou avec --mot-de-passe-stdin.');

            return false;
        }

        $username = (string) ($this->option('identifiant') ?: ($stdin ? 'admin' : $this->ask('Identifiant de l\'administrateur', 'admin')));
        $firstName = (string) ($this->option('prenom') ?: ($stdin ? 'Administrateur' : $this->ask('Prénom', 'Administrateur')));
        $lastName = (string) ($this->option('nom') ?: ($stdin ? 'Principal' : $this->ask('Nom', 'Principal')));

        $profile = Validator::make(
            ['username' => $username, 'first_name' => $firstName, 'last_name' => $lastName],
            [
                'username' => ['required', 'string', 'min:3', 'max:50', 'regex:/^[a-z0-9._-]+$/', 'unique:users,username'],
                'first_name' => ['required', 'string', 'max:100'],
                'last_name' => ['required', 'string', 'max:100'],
            ],
            ['username.regex' => 'L\'identifiant ne contient que des minuscules, chiffres, point, tiret et soulignement.'],
            ['username' => 'identifiant', 'first_name' => 'prénom', 'last_name' => 'nom'],
        );

        if ($profile->fails()) {
            $this->error($profile->errors()->first());

            return false;
        }

        $password = $stdin ? $this->passwordFromStdin() : $this->askPassword();

        if ($password === null) {
            return false;
        }

        $user = User::query()->create([
            'first_name' => $firstName,
            'last_name' => $lastName,
            'username' => $username,
            'job_title' => 'Administrateur de l\'application',
            'password' => $password,
            // Mot de passe transmis par un script (stdin) : connu de l'installateur, à changer à la première connexion.
            'must_change_password' => $stdin,
            'is_active' => true,
        ]);
        $user->assignRole(DefaultRoles::ADMIN);

        $this->line("Administrateur « {$username} » créé.");

        return true;
    }

    private function passwordFromStdin(): ?string
    {
        $line = fgets(self::$stdin ?? STDIN);
        $password = $line === false ? '' : rtrim($line, "\r\n");

        return $this->validPassword($password) ? $password : null;
    }

    private function askPassword(): ?string
    {
        for ($attempt = 1; $attempt <= 3; $attempt++) {
            $password = (string) $this->secret('Mot de passe (12 caractères minimum, lettres et chiffres ; non affiché)');

            if (! $this->validPassword($password)) {
                continue;
            }

            if ($password !== (string) $this->secret('Confirmez le mot de passe')) {
                $this->error('Les deux saisies sont différentes.');

                continue;
            }

            return $password;
        }

        $this->error('Mot de passe non défini après trois essais : aucun compte créé.');

        return null;
    }

    private function validPassword(string $password): bool
    {
        $validator = Validator::make(
            ['password' => $password],
            ['password' => ['required', 'string', Password::min(self::MINIMUM_PASSWORD_LENGTH)->letters()->numbers()]],
            [],
            ['password' => 'mot de passe'],
        );

        if ($validator->fails()) {
            $this->error($validator->errors()->first());

            return false;
        }

        return true;
    }
}
