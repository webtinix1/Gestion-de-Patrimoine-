<?php

use Illuminate\Support\Facades\File;
use Symfony\Component\Process\Process;

/*
 * RG-15 : plusieurs postes valident en même temps. De vrais processus PHP parallèles travaillent sur la même base.
 *
 * - SQLite (toujours) : les transactions IMMEDIATE sérialisent les écritures.
 * - MySQL / MariaDB : mêmes variables CONCURRENCE_DB_* que NumeroPatrimonialConcurrenceTest ;
 *   la base indiquée est entièrement vidée par le test.
 */

/**
 * @return array<string, string>|null
 */
function acquisitionConcurrencyEnv(string $driver): ?array
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
        $path = storage_path('framework/testing/concurrence-acq-'.getmypid().'.sqlite');
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
 */
function acquisitionWorker(array $env, string $mode, int $argument = 1): Process
{
    return new Process([PHP_BINARY, base_path('tests/Support/concurrent-acquisitions.php'), $mode, (string) $argument], base_path(), $env, timeout: 300);
}

/**
 * @param  list<Process>  $processes
 * @return list<string>
 */
function acquisitionWaitAll(array $processes): array
{
    foreach ($processes as $process) {
        $process->start();
    }

    $lines = [];

    foreach ($processes as $process) {
        $process->wait();
        expect($process->isSuccessful())->toBeTrue($process->getErrorOutput());
        array_push($lines, ...array_values(array_filter(array_map('trim', explode("\n", $process->getOutput())))));
    }

    return $lines;
}

/**
 * @param  array<string, string>  $env
 */
function prepareAcquisitionDatabase(array $env): void
{
    (new Process([PHP_BINARY, 'artisan', 'migrate:fresh', '--force', '--no-interaction'], base_path(), $env, timeout: 300))->mustRun();
    acquisitionWorker($env, 'setup')->mustRun();
}

afterEach(function () {
    if (($this->env['DB_CONNECTION'] ?? null) === 'sqlite') {
        File::delete($this->env['DB_DATABASE']);
    }
});

it('refuse la double validation simultanée : une seule réussit, les fiches ne sont créées qu\'une fois', function (string $driver) {
    $this->env = acquisitionConcurrencyEnv($driver) ?? $this->markTestSkipped("Aucune base {$driver} jetable indiquée (variables CONCURRENCE_DB_*).");
    prepareAcquisitionDatabase($this->env);

    $draft = acquisitionWorker($this->env, 'draft', 5);
    $draft->mustRun();
    $id = (int) trim($draft->getOutput());

    $results = acquisitionWaitAll(array_map(fn () => acquisitionWorker($this->env, 'validate', $id), range(1, 6)));

    $accepted = array_values(array_filter($results, fn (string $line): bool => str_starts_with($line, 'OK ')));

    expect($accepted)->toHaveCount(1)
        ->and(array_filter($results, fn (string $line): bool => $line === 'REFUS'))->toHaveCount(5);

    $report = acquisitionWaitAll([acquisitionWorker($this->env, 'report')]);

    expect($report)->toHaveCount(5)
        ->and(explode(',', substr($accepted[0], 3)))->toBe(['PAT-000001', 'PAT-000002', 'PAT-000003', 'PAT-000004', 'PAT-000005']);
})->with(['sqlite', 'mysql', 'mariadb']);

it('garde des numéros consécutifs par acquisition quand d\'autres postes créent des biens en même temps', function (string $driver) {
    $this->env = acquisitionConcurrencyEnv($driver) ?? $this->markTestSkipped("Aucune base {$driver} jetable indiquée (variables CONCURRENCE_DB_*).");
    prepareAcquisitionDatabase($this->env);

    $ids = [];

    foreach ([4, 6, 5] as $units) {
        $draft = acquisitionWorker($this->env, 'draft', $units);
        $draft->mustRun();
        $ids[] = (int) trim($draft->getOutput());
    }

    $workers = [
        ...array_map(fn (int $id) => acquisitionWorker($this->env, 'validate', $id), $ids),
        acquisitionWorker($this->env, 'assets', 8),
        acquisitionWorker($this->env, 'assets', 8),
    ];

    $results = acquisitionWaitAll($workers);

    $blocks = array_map(
        fn (string $line): array => array_map(fn (string $number): int => (int) substr($number, -6), explode(',', substr($line, 3))),
        array_values(array_filter($results, fn (string $line): bool => str_starts_with($line, 'OK '))),
    );
    $solo = array_map(fn (string $line): int => (int) substr($line, -6), array_values(array_filter($results, fn (string $line): bool => str_starts_with($line, 'SOLO '))));

    $all = collect([...array_merge(...$blocks), ...$solo])->sort()->values()->all();

    expect($blocks)->toHaveCount(3)
        ->and($all)->toBe(range(1, 31));

    foreach ($blocks as $block) {
        sort($block);
        expect($block)->toBe(range($block[0], $block[0] + count($block) - 1));
    }
})->with(['sqlite', 'mysql', 'mariadb']);
