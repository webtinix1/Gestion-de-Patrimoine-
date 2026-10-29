<?php

use Illuminate\Support\Facades\File;
use Symfony\Component\Process\Process;

/*
 * RG-50, RG-53 : plusieurs postes sortent le même bien au même instant. De vrais processus PHP
 * parallèles travaillent sur la même base ; une seule sortie passe, les autres sont refusées.
 *
 * - SQLite (toujours) : les transactions IMMEDIATE sérialisent les écritures.
 * - MySQL / MariaDB : mêmes variables CONCURRENCE_DB_* que NumeroPatrimonialConcurrenceTest ;
 *   la base indiquée est entièrement vidée par le test.
 */

/**
 * @return array<string, string>|null
 */
function disposalConcurrencyEnv(string $driver): ?array
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
        $path = storage_path('framework/testing/concurrence-srt-'.getmypid().'.sqlite');
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
function disposalWorker(array $env, string $mode, int $argument = 1): Process
{
    return new Process([PHP_BINARY, base_path('tests/Support/concurrent-disposals.php'), $mode, (string) $argument], base_path(), $env, timeout: 300);
}

/**
 * @param  list<Process>  $processes
 * @return list<string>
 */
function disposalWaitAll(array $processes): array
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
function prepareDisposalDatabase(array $env, int $assets): void
{
    (new Process([PHP_BINARY, 'artisan', 'migrate:fresh', '--force', '--no-interaction'], base_path(), $env, timeout: 300))->mustRun();
    disposalWorker($env, 'setup', $assets)->mustRun();
}

afterEach(function () {
    if (($this->env['DB_CONNECTION'] ?? null) === 'sqlite') {
        File::delete($this->env['DB_DATABASE']);
    }
});

it('ne laisse passer qu\'une seule de plusieurs sorties simultanées du même bien', function (string $driver) {
    $this->env = disposalConcurrencyEnv($driver) ?? $this->markTestSkipped("Aucune base {$driver} jetable indiquée (variables CONCURRENCE_DB_*).");
    prepareDisposalDatabase($this->env, 1);

    $results = disposalWaitAll(array_map(fn () => disposalWorker($this->env, 'dispose', 1), range(1, 6)));

    expect(array_values(array_filter($results, fn (string $line): bool => str_starts_with($line, 'OK '))))->toBe(['OK SRT-2026-001 PAT-000001'])
        ->and(array_filter($results, fn (string $line): bool => $line === 'REFUS'))->toHaveCount(5)
        ->and(disposalWaitAll([disposalWorker($this->env, 'report')]))->toBe([
            'SRT-2026-001 PAT-000001 2180000 retired',
            'LINES2026 493333',
            'SCHEDULES 1',
        ]);
})->with(['sqlite', 'mysql', 'mariadb']);

it('donne des références distinctes à des sorties simultanées de biens différents', function (string $driver) {
    $this->env = disposalConcurrencyEnv($driver) ?? $this->markTestSkipped("Aucune base {$driver} jetable indiquée (variables CONCURRENCE_DB_*).");
    prepareDisposalDatabase($this->env, 6);

    $results = disposalWaitAll(array_map(fn (int $index) => disposalWorker($this->env, 'dispose', $index), range(1, 6)));
    $references = collect($results)->map(fn (string $line): string => explode(' ', $line)[1] ?? '')->sort()->values()->all();

    expect($results)->toHaveCount(6)
        ->and($references)->toBe(array_map(fn (int $n): string => sprintf('SRT-2026-%03d', $n), range(1, 6)));
})->with(['sqlite', 'mysql', 'mariadb']);

it('mène à terme une clôture d\'exercice et une sortie simultanées (M-3, RG-36)', function (string $driver) {
    $this->env = disposalConcurrencyEnv($driver) ?? $this->markTestSkipped("Aucune base {$driver} jetable indiquée (variables CONCURRENCE_DB_*).");
    (new Process([PHP_BINARY, 'artisan', 'migrate:fresh', '--force', '--no-interaction'], base_path(), $this->env, timeout: 600))->mustRun();
    $script = fn (string $mode): Process => new Process([PHP_BINARY, base_path('tests/Support/concurrent-close-dispose.php'), $mode], base_path(), $this->env, timeout: 600);
    $script('setup')->mustRun();

    $results = disposalWaitAll([$script('close'), $script('dispose')]);
    sort($results);

    expect($results)->toBe(['CLOSE OK', 'DISPOSE OK 2180000'])
        ->and(disposalWaitAll([$script('report')]))->toBe([
            'YEARS 2024:closed,2025:closed,2026:open',
            'LINES 2022:326667:1,2023:600000:1,2024:600000:1,2025:600000:1,2026:493333:0',
        ]);
})->with(['sqlite', 'mysql', 'mariadb']);

it('ne laisse aucun fichier orphelin quand la sortie est rejouée après un interblocage', function () {
    $this->env = disposalConcurrencyEnv('sqlite');
    prepareDisposalDatabase($this->env, 1);
    $disk = storage_path('framework/testing/disque-srt-'.getmypid());
    File::deleteDirectory($disk);

    $retry = new Process([PHP_BINARY, base_path('tests/Support/concurrent-disposals.php'), 'retry'], base_path(), [...$this->env, 'DISK_ROOT' => $disk], timeout: 300);

    expect(disposalWaitAll([$retry]))->toBe(['OK SRT-2026-001', 'DOCUMENTS 1', 'FILES 1', 'SAME yes']);

    File::deleteDirectory($disk);
});

it('rejoue la clôture d\'exercice désignée comme victime d\'un interblocage (M-3)', function () {
    $this->env = disposalConcurrencyEnv('sqlite');
    (new Process([PHP_BINARY, 'artisan', 'migrate:fresh', '--force', '--no-interaction'], base_path(), $this->env, timeout: 600))->mustRun();
    $script = fn (string $mode): Process => new Process([PHP_BINARY, base_path('tests/Support/concurrent-close-dispose.php'), $mode], base_path(), $this->env, timeout: 600);
    $script('setup')->mustRun();

    expect(disposalWaitAll([$script('close-deadlock')]))->toBe(['CLOSE OK'])
        ->and(disposalWaitAll([$script('report')])[0])->toBe('YEARS 2024:closed,2025:closed,2026:open');
});
