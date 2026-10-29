<?php

use Illuminate\Support\Facades\File;
use Symfony\Component\Process\Process;

/*
 * RG-03 : plusieurs postes créent des biens au même instant, aucun numéro en double ni d'échec.
 * De vrais processus PHP parallèles travaillent sur la même base.
 *
 * - SQLite (toujours) : les transactions IMMEDIATE sérialisent les écritures.
 * - MySQL / MariaDB (cible de production) : verrou de ligne sur le compteur. Ce cas ne tourne
 *   que si une base jetable est indiquée, par exemple :
 *   CONCURRENCE_DB_DRIVER=mariadb CONCURRENCE_DB_HOST=127.0.0.1 CONCURRENCE_DB_PORT=3399
 *   CONCURRENCE_DB_DATABASE=pat CONCURRENCE_DB_USERNAME=root CONCURRENCE_DB_PASSWORD= php artisan test --filter=Concurrence
 *   La base indiquée est entièrement vidée par le test.
 */

/**
 * @return array<string, string>|null
 */
function concurrencyEnv(string $driver): ?array
{
    $common = [
        'APP_ENV' => 'testing',
        'DB_URL' => '',
        'CACHE_STORE' => 'array',
        'SESSION_DRIVER' => 'array',
        'QUEUE_CONNECTION' => 'sync',
        'ACTIVITY_LOGGER_ENABLED' => 'false',
    ];

    if ($driver === 'sqlite') {
        $path = storage_path('framework/testing/concurrence-'.getmypid().'.sqlite');
        File::ensureDirectoryExists(dirname($path));
        File::delete($path);
        touch($path);

        return [...$common, 'DB_CONNECTION' => 'sqlite', 'DB_DATABASE' => $path];
    }

    if (! concurrencyDb('DATABASE') || concurrencyDb('DRIVER') !== $driver) {
        return null;
    }

    return [
        ...$common,
        'DB_CONNECTION' => $driver,
        'DB_HOST' => concurrencyDb('HOST'),
        'DB_PORT' => concurrencyDb('PORT'),
        'DB_DATABASE' => concurrencyDb('DATABASE'),
        'DB_USERNAME' => concurrencyDb('USERNAME'),
        'DB_PASSWORD' => concurrencyDb('PASSWORD'),
    ];
}

/**
 * @param  array<string, string>  $env
 * @return list<string>
 */
function runInParallel(array $env, string $mode, int $processes, int $perProcess): array
{
    $script = base_path('tests/Support/concurrent-assets.php');

    $running = collect(range(1, $processes))->map(function () use ($env, $script, $mode, $perProcess): Process {
        $process = new Process([PHP_BINARY, $script, $mode, (string) $perProcess], base_path(), $env, timeout: 300);
        $process->start();

        return $process;
    });

    return $running->flatMap(function (Process $process): array {
        $process->wait();

        expect($process->isSuccessful())->toBeTrue($process->getErrorOutput());

        return array_values(array_filter(array_map('trim', explode("\n", $process->getOutput()))));
    })->all();
}

/**
 * @param  array<string, string>  $env
 */
function prepareConcurrencyDatabase(array $env): void
{
    (new Process([PHP_BINARY, 'artisan', 'migrate:fresh', '--force', '--no-interaction'], base_path(), $env, timeout: 300))->mustRun();
    (new Process([PHP_BINARY, base_path('tests/Support/concurrent-assets.php'), 'setup'], base_path(), $env, timeout: 60))->mustRun();
}

afterEach(function () {
    if (($this->env['DB_CONNECTION'] ?? null) === 'sqlite') {
        File::delete($this->env['DB_DATABASE']);
    }
});

it('génère des numéros uniques et consécutifs quand plusieurs postes demandent un numéro en même temps', function (string $driver) {
    $this->env = concurrencyEnv($driver) ?? $this->markTestSkipped("Aucune base {$driver} jetable indiquée (variables CONCURRENCE_DB_*).");
    prepareConcurrencyDatabase($this->env);

    $numbers = runInParallel($this->env, 'numbers', processes: 6, perProcess: 15);

    $expected = collect(range(1, 90))->map(fn (int $n) => 'PAT-'.str_pad((string) $n, 6, '0', STR_PAD_LEFT))->all();

    expect($numbers)->toHaveCount(90)
        ->and(collect($numbers)->sort()->values()->all())->toBe($expected);
})->with(['sqlite', 'mysql', 'mariadb']);

it('crée des biens simultanément sans doublon ni échec', function (string $driver) {
    $this->env = concurrencyEnv($driver) ?? $this->markTestSkipped("Aucune base {$driver} jetable indiquée (variables CONCURRENCE_DB_*).");
    prepareConcurrencyDatabase($this->env);

    $numbers = runInParallel($this->env, 'assets', processes: 6, perProcess: 10);

    expect($numbers)->toHaveCount(60)
        ->and(array_unique($numbers))->toHaveCount(60);
})->with(['sqlite', 'mysql', 'mariadb']);
