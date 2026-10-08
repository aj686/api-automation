<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use RuntimeException;

abstract class TestCase extends BaseTestCase
{
    /**
     * Refuse to run against anything but a dedicated test database.
     *
     * plan section 11, loophole 30: RefreshDatabase would happily migrate:fresh
     * the development database and destroy real run history.
     *
     * The check runs BEFORE parent::setUp() on purpose. parent::setUp() calls
     * setUpTraits(), which is where RefreshDatabase actually wipes the schema —
     * a check placed after it would fire too late to protect anything.
     *
     * It reads the raw environment rather than config(), because the container
     * is not booted yet. PHPUnit has already applied the <env> entries from
     * phpunit.xml at this point, and Laravel loads .env with an immutable
     * Dotenv repository, so these values are the ones the connection will use.
     */
    protected function setUp(): void
    {
        $this->assertTestDatabase();

        parent::setUp();
    }

    private function assertTestDatabase(): void
    {
        $database = $_ENV['DB_DATABASE']
            ?? $_SERVER['DB_DATABASE']
            ?? (getenv('DB_DATABASE') ?: null);

        if (! is_string($database) || $database === '') {
            throw new RuntimeException(
                'Refusing to run tests: DB_DATABASE is not set. '
                .'Expected a database whose name ends in "_testing" '
                .'(see phpunit.xml).'
            );
        }

        if (! str_ends_with($database, '_testing')) {
            throw new RuntimeException(sprintf(
                'Refusing to run tests against database [%s]: the name must end '
                .'in "_testing". Tests use RefreshDatabase and would destroy the '
                .'data in that database. Check phpunit.xml and your .env.',
                $database
            ));
        }
    }
}
