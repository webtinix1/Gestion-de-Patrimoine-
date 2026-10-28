<?php

use Illuminate\Support\Facades\File;
use Symfony\Component\Process\Process;

/*
 * RG-55, RG-60, RG-63 : plusieurs postes prêtent, ouvrent des interventions et signalent en même temps. De vrais
 * processus PHP parallèles travaillent sur la même base.
 *
 * - SQLite (toujours) : les transactions IMMEDIATE sérialisent les écritures.
 * - MySQL / MariaDB : mêmes variables CONCURRENCE_DB_* que NumeroPatrimonialConcurrenceTest ;
 *   la base indiquée est entièrement vidée par le test.
 */

/**
 * @return array<string, string>|null
 */
function operationsEnv(string $driver): ?array
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
        $path = storage_path('framework/testing/concurrence-ops-'.getmypid().'.sqlite');
        File::ensureDirectoryExists(dirname($path));
        File::delete($path);
        touch($path);

        return [...$common, 'DB_CONNECTION' => 'sqlite', 'DB_DATABASE' => $path];
    }

    if (! getenv('CONCURRENCE_DB_DATABASE') || getenv('CONCURRENCE_DB_DRIVER') !== $driver) {
        return null;
    }

    return [
        ...$common,
        'DB_CONNECTION' => $driver,
        'DB_HOST' => (string) getenv('CONCURRENCE_DB_HOST'),
        'DB_PORT' => (string) getenv('CONCURRENCE_DB_PORT'),
        'DB_DATABASE' => (string) getenv('CONCURRENCE_DB_DATABASE'),
        'DB_USERNAME' => (string) getenv('CONCURRENCE_DB_USERNAME'),
        'DB_PASSWORD' => (string) getenv('CONCURRENCE_DB_PASSWORD'),
    ];
}

/**
 * @param  array<string, string>  $env
 */
function operationsWorker(array $env, string $mode, int $argument = 1): Process
{
    return new Process([PHP_BINARY, base_path('tests/Support/concurrent-operations.php'), $mode, (string) $argument], base_path(), $env, timeout: 300);
}

/**
 * @param  list<Process>  $processes
 * @return list<string>
 */
function operationsWaitAll(array $processes): array
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
function prepareOperationsDatabase(array $env, int $assets): void
{
    (new Process([PHP_BINARY, 'artisan', 'migrate:fresh', '--force', '--no-interaction'], base_path(), $env, timeout: 300))->mustRun();
    operationsWorker($env, 'setup', $assets)->mustRun();
}

afterEach(function () {
    if (($this->env['DB_CONNECTION'] ?? null) === 'sqlite') {
        File::delete($this->env['DB_DATABASE']);
    }
});

it('prête une seule fois un bien demandé par plusieurs postes en même temps (RG-55, RG-56)', function (string $driver) {
    $this->env = operationsEnv($driver) ?? $this->markTestSkipped("Aucune base {$driver} jetable indiquée (variables CONCURRENCE_DB_*).");
    prepareOperationsDatabase($this->env, 1);

    $results = operationsWaitAll(array_map(fn () => operationsWorker($this->env, 'loan', 1), range(1, 6)));

    expect(array_values(array_filter($results, fn (string $line): bool => str_starts_with($line, 'OK '))))->toHaveCount(1)
        ->and(array_filter($results, fn (string $line): bool => $line === 'REFUS'))->toHaveCount(5, implode(' | ', $results));

    $dump = operationsWaitAll([operationsWorker($this->env, 'dump')]);

    expect($dump)->toContain('ASSET 1 on_loan')
        ->and(array_values(array_filter($dump, fn (string $line): bool => str_starts_with($line, 'LOAN '))))->toHaveCount(1);
})->with(['sqlite', 'mysql', 'mariadb']);

it('attribue des références de prêt, d\'intervention et de signalement uniques et sans trou, quand les postes travaillent ensemble', function (string $driver) {
    $this->env = operationsEnv($driver) ?? $this->markTestSkipped("Aucune base {$driver} jetable indiquée (variables CONCURRENCE_DB_*).");
    prepareOperationsDatabase($this->env, 12);

    $workers = [
        ...array_map(fn (int $id) => operationsWorker($this->env, 'loan', $id), range(1, 4)),
        ...array_map(fn (int $id) => operationsWorker($this->env, 'open', $id), range(5, 8)),
        ...array_map(fn (int $id) => operationsWorker($this->env, 'report', $id), range(9, 12)),
    ];

    $results = operationsWaitAll($workers);
    $references = array_map(fn (string $line): string => substr($line, 3), array_values(array_filter($results, fn (string $line): bool => str_starts_with($line, 'OK '))));
    $year = date('Y');

    sort($references);

    expect($references)->toBe([
        "MNT-{$year}-001", "MNT-{$year}-002", "MNT-{$year}-003", "MNT-{$year}-004",
        "PRT-{$year}-001", "PRT-{$year}-002", "PRT-{$year}-003", "PRT-{$year}-004",
        "SIG-{$year}-001", "SIG-{$year}-002", "SIG-{$year}-003", "SIG-{$year}-004",
    ]);
})->with(['sqlite', 'mysql', 'mariadb']);

it('n\'ouvre qu\'une intervention à la fois sur un même bien, et ne rend un prêt qu\'une fois (RG-60, RG-56)', function (string $driver) {
    $this->env = operationsEnv($driver) ?? $this->markTestSkipped("Aucune base {$driver} jetable indiquée (variables CONCURRENCE_DB_*).");
    prepareOperationsDatabase($this->env, 2);

    $opened = operationsWaitAll(array_map(fn () => operationsWorker($this->env, 'open', 1), range(1, 6)));

    expect(array_values(array_filter($opened, fn (string $line): bool => str_starts_with($line, 'OK '))))->toHaveCount(1)
        ->and(array_filter($opened, fn (string $line): bool => $line === 'REFUS'))->toHaveCount(5, implode(' | ', $opened));

    operationsWaitAll([operationsWorker($this->env, 'loan', 2)]);

    $returns = operationsWaitAll(array_map(fn () => operationsWorker($this->env, 'return', 1), range(1, 5)));

    expect(array_filter($returns, fn (string $line): bool => $line === 'OK'))->toHaveCount(1)
        ->and(array_filter($returns, fn (string $line): bool => $line === 'REFUS'))->toHaveCount(4)
        ->and(operationsWaitAll([operationsWorker($this->env, 'dump')]))->toContain('ASSET 2 in_service');
})->with(['sqlite', 'mysql', 'mariadb']);
