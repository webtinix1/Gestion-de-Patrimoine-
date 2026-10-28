<?php

use Illuminate\Support\Facades\File;
use Symfony\Component\Process\Process;

/*
 * RG-47 : plusieurs personnes saisissent le même inventaire en même temps. De vrais processus PHP
 * parallèles travaillent sur la même base.
 *
 * - SQLite (toujours) : les transactions IMMEDIATE sérialisent les écritures.
 * - MySQL / MariaDB : verrou exclusif de l'inventaire puis de la ligne. Mêmes variables
 *   CONCURRENCE_DB_* que NumeroPatrimonialConcurrenceTest ; la base indiquée est entièrement vidée.
 */

/**
 * @return array<string, string>|null
 */
function inventoryConcurrencyEnv(string $driver): ?array
{
    $common = [
        'APP_ENV' => 'testing',
        'DB_URL' => '',
        'CACHE_STORE' => 'array',
        'SESSION_DRIVER' => 'array',
        'QUEUE_CONNECTION' => 'sync',
        'ACTIVITY_LOGGER_ENABLED' => 'true',
    ];

    if ($driver === 'sqlite') {
        $path = storage_path('framework/testing/concurrence-inv-'.getmypid().'.sqlite');
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
 * @param  list<string>  $arguments
 */
function inventoryWorker(array $env, string $mode, array $arguments = []): Process
{
    return new Process([PHP_BINARY, base_path('tests/Support/concurrent-inventory.php'), $mode, ...$arguments], base_path(), $env, timeout: 300);
}

/**
 * @param  list<Process>  $processes
 * @return list<string>
 */
function inventoryRunAll(array $processes): array
{
    foreach ($processes as $process) {
        $process->start();
    }

    $lines = [];

    foreach ($processes as $process) {
        $process->wait();
        expect($process->isSuccessful())->toBeTrue($process->getErrorOutput().$process->getOutput());
        array_push($lines, ...array_values(array_filter(array_map('trim', explode("\n", $process->getOutput())))));
    }

    return $lines;
}

/**
 * @param  array<string, string>  $env
 */
function prepareInventoryDatabase(array $env, int $assets): int
{
    (new Process([PHP_BINARY, 'artisan', 'migrate:fresh', '--force', '--no-interaction'], base_path(), $env, timeout: 300))->mustRun();
    $setup = inventoryWorker($env, 'setup', [(string) $assets]);
    $setup->mustRun();

    return (int) trim($setup->getOutput());
}

afterEach(function () {
    if (($this->env['DB_CONNECTION'] ?? null) === 'sqlite') {
        File::delete($this->env['DB_DATABASE']);
    }
});

it('saisit à plusieurs postes les mêmes biens en même temps : un résultat par bien, aucun écrasement (RG-47)', function (string $driver) {
    $this->env = inventoryConcurrencyEnv($driver) ?? $this->markTestSkipped("Aucune base {$driver} jetable indiquée (variables CONCURRENCE_DB_*).");
    $id = prepareInventoryDatabase($this->env, 30);

    $outcomes = inventoryRunAll(array_map(fn (int $poste) => inventoryWorker($this->env, 'scan', [(string) $id, (string) $poste]), range(1, 5)));
    $statuses = collect($outcomes)->map(fn (string $line): string => explode(' ', $line)[0]);

    $report = json_decode(inventoryRunAll([inventoryWorker($this->env, 'report', [(string) $id])])[0], true);

    // 31 biens lus par 5 postes : chaque bien est enregistré une seule fois, les autres lectures le constatent.
    expect($outcomes)->toHaveCount(155)
        ->and($statuses->filter(fn (string $s): bool => $s === 'recorded')->count())->toBe(30)
        ->and($statuses->filter(fn (string $s): bool => $s === 'out_of_scope')->count())->toBe(1)
        ->and($statuses->filter(fn (string $s): bool => $s === 'already')->count())->toBe(124)
        ->and($statuses->filter(fn (string $s): bool => $s === 'conflict')->count())->toBe(0)
        ->and($report['lines'])->toBe(31)
        ->and($report['max_per_asset'])->toBe(1)
        ->and($report['results'])->toEqual(['present' => 30, 'out_of_scope' => 1])
        ->and($report['journal'])->toBe(31)
        ->and($report['journal_by_line'])->toBe(1);
})->with(['sqlite', 'mysql', 'mariadb']);

it('ne retient qu\'une saisie quand plusieurs postes corrigent la même ligne vue à la même version (RG-47)', function (string $driver) {
    $this->env = inventoryConcurrencyEnv($driver) ?? $this->markTestSkipped("Aucune base {$driver} jetable indiquée (variables CONCURRENCE_DB_*).");
    $id = prepareInventoryDatabase($this->env, 3);

    [$lineId, $version] = explode(' ', inventoryRunAll([inventoryWorker($this->env, 'line', [(string) $id, 'PAT-000002'])])[0]);

    $results = inventoryRunAll(array_map(
        fn (int $poste) => inventoryWorker($this->env, 'mark', [(string) $id, (string) $poste, $lineId, $version, $poste % 2 === 0 ? 'present' : 'missing']),
        range(1, 6),
    ));

    $accepted = array_values(array_filter($results, fn (string $line): bool => str_starts_with($line, 'OK ')));
    $report = json_decode(inventoryRunAll([inventoryWorker($this->env, 'report', [(string) $id])])[0], true);
    $winner = substr($accepted[0] ?? 'OK ?', 3);

    expect($accepted)->toHaveCount(1)
        ->and(array_filter($results, fn (string $line): bool => $line === 'CONFLIT'))->toHaveCount(5)
        ->and($report['results'][$winner] ?? 0)->toBe(1)
        ->and($report['journal'])->toBe(1);
})->with(['sqlite', 'mysql', 'mariadb']);
