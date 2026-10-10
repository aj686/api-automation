<?php

namespace Tests\Feature\Runner;

use App\Enums\RunStatus;
use App\Jobs\ExecuteRun;
use App\Models\Environment;
use App\Models\Run;
use App\Services\CollectionImporter;
use App\Services\Postman\PostmanCli;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Process\ExecutableFinder;
use Tests\TestCase;

/**
 * The real Postman CLI, end to end. Runs only where it is installed — the
 * worker container:
 *
 *   docker exec -w /var/www/projects/api-automation/api-automation \
 *       api-automation-worker php artisan test --filter=RealPostmanCliTest
 *
 * Target: this app's own /up route through the nginx container.
 */
class RealPostmanCliTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_real_run_end_to_end(): void
    {
        if ((new ExecutableFinder)->find('postman') === null) {
            $this->markTestSkipped('Postman CLI is not installed here; run this in the api-automation-worker container.');
        }

        Storage::fake(CollectionImporter::DISK);
        config(['automation.postman_cli_path' => 'postman', 'automation.runs_directory' => storage_path('framework/testing/real-runs')]);

        $environment = Environment::factory()->create();
        $environment->variables()->createMany([
            ['key' => 'base_url', 'value' => 'http://nginx'],
            ['key' => 'host', 'value' => 'api-automation.local'],
            ['key' => 'api_key', 'value' => 'YOUR_API_KEY', 'is_secret' => true],
        ]);
        $collection = app(CollectionImporter::class)->store($environment->project, json_encode([
            'info' => ['name' => 'Real Smoke', 'schema' => 'https://schema.getpostman.com/json/collection/v2.1.0/collection.json'],
            'item' => [[
                'name' => 'Health',
                'request' => ['method' => 'GET', 'header' => [['key' => 'Host', 'value' => '{{host}}']], 'url' => '{{base_url}}/up?api_key={{api_key}}'],
                'event' => [['listen' => 'test', 'script' => ['exec' => ['pm.test("status is 200", function () { pm.response.to.have.status(200); });']]]],
            ]],
        ]), 'real.json');
        $run = Run::factory()->create(['project_id' => $environment->project_id, 'environment_id' => $environment->id, 'collection_id' => $collection->id]);

        (new ExecuteRun($run->id))->handle(app(PostmanCli::class));
        $run->refresh();

        $this->assertSame(RunStatus::Pass, $run->status, (string) $run->error_message);
        $this->assertSame([1, 1, 0], [$run->total_assertions, $run->passed_assertions, $run->failed_assertions]);
        $this->assertMatchesRegularExpression('/^\d+\.\d+\.\d+$/', $run->cli_version);
        $this->assertSame('http://nginx/up?api_key=********', $run->results()->sole()->url);
        $this->assertStringContainsString('status is 200', $run->log);
        $this->assertStringNotContainsString('YOUR_API_KEY', $run->log);
    }
}
