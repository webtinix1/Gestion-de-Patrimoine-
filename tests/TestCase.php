<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use RuntimeException;

abstract class TestCase extends BaseTestCase
{
    /**
     * Les tests vident la base : jamais ailleurs que sur une base jetable (SQLite en mémoire ou nom en « _test »),
     * même si une variable d'environnement DB_DATABASE pointe vers la base réelle.
     *
     * @return array<class-string, class-string>
     */
    protected function setUpTraits()
    {
        $database = (string) config('database.connections.'.config('database.default').'.database');

        if ($database !== ':memory:' && ! str_ends_with($database, '_test')) {
            throw new RuntimeException("Tests refusés sur la base « {$database} » : seule une base jetable (nom en « _test », ou SQLite :memory:) est acceptée.");
        }

        return parent::setUpTraits();
    }
}
