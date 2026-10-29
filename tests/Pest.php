<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');

/**
 * Paramètre de la base jetable des tests de concurrence (variables CONCURRENCE_DB_*, fixées dans phpunit.xml).
 * Identifiant et mot de passe absents : ceux de la connexion de test (DB_USERNAME, DB_PASSWORD du .env ou de la CI).
 */
function concurrencyDb(string $key): string
{
    $value = getenv('CONCURRENCE_DB_'.$key);

    if (is_string($value) && $value !== '') {
        return $value;
    }

    return match ($key) {
        'USERNAME', 'PASSWORD' => (string) config('database.connections.'.config('database.default').'.'.strtolower($key)),
        default => '',
    };
}
