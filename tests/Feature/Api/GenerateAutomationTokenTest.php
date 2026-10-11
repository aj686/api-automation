<?php

namespace Tests\Feature\Api;

use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * Runs against a throwaway .env in a temp directory — never the real one.
 */
class GenerateAutomationTokenTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        parent::setUp();

        $this->directory = storage_path('framework/testing/env-'.getmypid());
        File::ensureDirectoryExists($this->directory);
        $this->app->useEnvironmentPath($this->directory);
        $this->assertNotSame(base_path('.env'), $this->app->environmentFilePath(), 'must not touch the real .env');
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->directory);
        parent::tearDown();
    }

    private function env(): string
    {
        return file_get_contents($this->app->environmentFilePath());
    }

    public function test_it_fills_an_empty_token_line(): void
    {
        file_put_contents($this->app->environmentFilePath(), "APP_NAME=x\nAUTOMATION_API_TOKEN=\nOTHER=y\n");

        $this->artisan('automation:token')->assertSuccessful();

        $this->assertMatchesRegularExpression('/^APP_NAME=x\nAUTOMATION_API_TOKEN=[A-Za-z0-9]{64}\nOTHER=y\n$/', $this->env());
    }

    public function test_it_appends_the_line_when_missing(): void
    {
        file_put_contents($this->app->environmentFilePath(), "APP_NAME=x\n");

        $this->artisan('automation:token')->assertSuccessful();

        $this->assertMatchesRegularExpression('/\nAUTOMATION_API_TOKEN=[A-Za-z0-9]{64}\n$/', $this->env());
    }

    public function test_it_refuses_to_replace_a_token_without_force(): void
    {
        file_put_contents($this->app->environmentFilePath(), "AUTOMATION_API_TOKEN=YOUR_EXISTING_TOKEN\n");

        $this->artisan('automation:token')->assertFailed();
        $this->assertSame("AUTOMATION_API_TOKEN=YOUR_EXISTING_TOKEN\n", $this->env());

        $this->artisan('automation:token --force')->assertSuccessful();
        $this->assertStringNotContainsString('YOUR_EXISTING_TOKEN', $this->env());
    }

    public function test_each_token_is_different(): void
    {
        file_put_contents($this->app->environmentFilePath(), "AUTOMATION_API_TOKEN=\n");
        $this->artisan('automation:token')->assertSuccessful();
        $first = $this->env();

        $this->artisan('automation:token --force')->assertSuccessful();

        $this->assertNotSame($first, $this->env());
    }
}
