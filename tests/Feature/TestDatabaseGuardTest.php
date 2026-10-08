<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class TestDatabaseGuardTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_runs_against_a_dedicated_test_database(): void
    {
        $database = DB::connection()->getDatabaseName();

        $this->assertStringEndsWith('_testing', $database);
        $this->assertSame('api_automation_testing', $database);
    }

    public function test_refresh_database_actually_migrated_here(): void
    {
        // Proves the connection is live and RefreshDatabase ran the schema
        // against the test database, not the development one.
        $this->assertTrue(DB::getSchemaBuilder()->hasTable('migrations'));
        $this->assertSame('api_automation_testing', DB::connection()->getDatabaseName());
    }
}
