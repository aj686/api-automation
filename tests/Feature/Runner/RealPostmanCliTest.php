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
                'request' => ['method' => 'GET', 'header' => [['key' => 'Host', 'value' => '{{host}}'], ['key' => 'X-Api-Key', 'value' => '{{api_key}}']], 'url' => '{{base_url}}/up?api_key={{api_key}}'],
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

        // Payloads (D-037): the real response body is stored, the secret header masked.
        $request = $run->requests()->sole();
        $this->assertStringContainsString('<!DOCTYPE html>', $request->response_body);
        $this->assertSame('text/html; charset=utf-8', $request->response_content_type);
        $this->assertSame('********', collect($request->request_headers)->firstWhere('name', 'X-Api-Key')['value']);
        $this->assertStringNotContainsString('YOUR_API_KEY', $request->toJson());
        $this->assertSame($request->id, $run->results()->sole()->run_request_id);
    }

    public function test_a_real_run_sequence_keeps_the_chosen_order_and_bail_stops_it(): void
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
        ]);
        // No ids in the file: the importer adds them (D-038).
        $request = fn (string $name, int $expect) => [
            'name' => $name,
            'request' => ['method' => 'GET', 'header' => [['key' => 'Host', 'value' => '{{host}}']], 'url' => '{{base_url}}/up?r='.$name],
            'event' => [['listen' => 'test', 'script' => ['exec' => ['pm.test("'.$name.' is '.$expect.'", function () { pm.response.to.have.status('.$expect.'); });']]]],
        ];
        $collection = app(CollectionImporter::class)->store($environment->project, json_encode([
            'info' => ['name' => 'Real Sequence', 'schema' => 'https://schema.getpostman.com/json/collection/v2.1.0/collection.json'],
            'item' => [['name' => 'Folder', 'item' => [$request('A', 200), $request('B', 201)]], $request('C', 200)],
        ]), 'real.json');
        $ids = collect(app(CollectionImporter::class)->document($collection)->requests())->keyBy('name')->map(fn ($r) => $r['id']);

        $runWith = function (array $names, bool $bail) use ($environment, $collection, $ids): Run {
            $run = Run::factory()->create([
                'project_id' => $environment->project_id, 'environment_id' => $environment->id, 'collection_id' => $collection->id,
                'options' => ['items' => array_map(fn ($n) => ['id' => $ids[$n], 'name' => $n], $names), 'bail' => $bail],
            ]);
            (new ExecuteRun($run->id))->handle(app(PostmanCli::class));

            return $run->fresh();
        };

        $run = $runWith(['C', 'A'], false);
        $this->assertSame(RunStatus::Pass, $run->status, (string) $run->error_message);
        $this->assertSame(['C', 'A'], $run->requests()->orderBy('position')->pluck('item_name')->all());

        $run = $runWith(['B', 'C'], false);
        $this->assertSame(RunStatus::Fail, $run->status);
        $this->assertSame(['B', 'C'], $run->requests()->orderBy('position')->pluck('item_name')->all());

        $run = $runWith(['B', 'C'], true);
        $this->assertSame(RunStatus::Fail, $run->status, 'bail still reports the failure');
        $this->assertSame(['B'], $run->requests()->orderBy('position')->pluck('item_name')->all(), 'bail stopped after B');
    }

    public function test_a_real_send_keeps_the_login_token_for_the_next_send(): void
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
        ]);
        $get = ['method' => 'GET', 'header' => [['key' => 'Host', 'value' => '{{host}}']], 'url' => '{{base_url}}/up'];
        $collection = app(CollectionImporter::class)->store($environment->project, json_encode([
            'info' => ['name' => 'Real Keep', 'schema' => 'https://schema.getpostman.com/json/collection/v2.1.0/collection.json'],
            'item' => [
                ['id' => 'login', 'name' => 'Login', 'request' => $get, 'event' => [['listen' => 'test', 'script' => ['exec' => ['pm.environment.set("token", "YOUR_ACCESS_TOKEN_real");']]]]],
                ['id' => 'info', 'name' => 'GetProjectInfo', 'request' => $get, 'event' => [['listen' => 'test', 'script' => ['exec' => ['pm.test("token from Login is here", () => pm.expect(pm.environment.get("token")).to.eql("YOUR_ACCESS_TOKEN_real"));']]]]],
            ],
        ]), 'real.json');

        $send = function (string $id, string $name) use ($environment, $collection): Run {
            $run = Run::factory()->create([
                'project_id' => $environment->project_id, 'environment_id' => $environment->id, 'collection_id' => $collection->id,
                'trigger' => 'send', 'options' => ['items' => [['id' => $id, 'name' => $name]], 'bail' => false, 'keep' => true],
            ]);
            (new ExecuteRun($run->id))->handle(app(PostmanCli::class));

            return $run->fresh();
        };

        $before = $send('info', 'GetProjectInfo');
        $this->assertSame(RunStatus::Fail, $before->status, 'no token before Login');

        $login = $send('login', 'Login');
        $this->assertEquals(['saved' => ['token'], 'skipped' => []], $login->kept_variables);
        $this->assertSame('YOUR_ACCESS_TOKEN_real', $environment->variables()->where('key', 'token')->value('value'));

        $after = $send('info', 'GetProjectInfo');
        $this->assertSame(RunStatus::Pass, $after->status, (string) $after->error_message);
        $this->assertStringNotContainsString('YOUR_ACCESS_TOKEN_real', (string) $after->log.(string) $login->log);
        $this->assertStringNotContainsString('AAVARS', (string) $login->log);
    }
}
