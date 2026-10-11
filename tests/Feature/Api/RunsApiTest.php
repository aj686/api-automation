<?php

namespace Tests\Feature\Api;

use App\Enums\RunStatus;
use App\Enums\RunTrigger;
use App\Jobs\ExecuteRun;
use App\Models\Collection;
use App\Models\Environment;
use App\Models\EnvironmentVariable;
use App\Models\Project;
use App\Models\Run;
use App\Models\RunResult;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * The n8n contract (plan section 8, docs/n8n.md).
 */
class RunsApiTest extends TestCase
{
    use RefreshDatabase;

    private const TOKEN = 'YOUR_ACCESS_TOKEN_0123456789abcdefghijklmnopqrstuvwxyz';

    protected function setUp(): void
    {
        parent::setUp();
        config(['automation.api_token' => self::TOKEN]);
        Queue::fake();
    }

    private function api(): static
    {
        return $this->withHeader('Authorization', 'Bearer '.self::TOKEN);
    }

    /**
     * @return array{Project, Environment, Collection}
     */
    private function target(string $type = 'staging'): array
    {
        $project = Project::factory()->create(['slug' => 'demo-api']);
        $environment = Environment::factory()->for($project)->create(['slug' => 'staging', 'type' => $type]);
        $collection = Collection::factory()->for($project)->create(['slug' => 'smoke']);

        return [$project, $environment, $collection];
    }

    private function start(array $body = ['project' => 'demo-api', 'environment' => 'staging', 'collection' => 'smoke']): TestResponse
    {
        return $this->api()->postJson('/api/v1/runs', $body);
    }

    public function test_every_endpoint_needs_the_token(): void
    {
        $run = Run::factory()->create();

        $this->getJson('/api/v1/catalog')->assertUnauthorized();
        $this->postJson('/api/v1/runs', [])->assertUnauthorized();
        $this->getJson('/api/v1/runs/'.$run->id)->assertUnauthorized();
    }

    public function test_catalog_lists_slugs_and_never_variables(): void
    {
        [, $environment] = $this->target();
        EnvironmentVariable::factory()->secret()->create(['environment_id' => $environment->id, 'key' => 'api_key', 'value' => 'YOUR_API_KEY_catalog']);

        $response = $this->api()->getJson('/api/v1/catalog')->assertOk()->assertExactJson(['projects' => [[
            'slug' => 'demo-api',
            'name' => $environment->project->name,
            'environments' => [['slug' => 'staging', 'name' => $environment->name, 'type' => 'staging', 'allow_automation' => true]],
            'collections' => [['slug' => 'smoke', 'name' => Collection::sole()->name, 'kind' => Collection::sole()->kind->value]],
        ]]]);

        $this->assertStringNotContainsString('YOUR_API_KEY_catalog', $response->getContent());
        $this->assertStringNotContainsString('stored_path', $response->getContent());
    }

    public function test_starting_a_run(): void
    {
        $this->target();

        $response = $this->start()->assertStatus(202)->assertJsonPath('status', 'QUEUED');

        $run = Run::sole();
        $response->assertJsonPath('id', $run->id)
            ->assertJsonPath('status_url', route('api.runs.show', $run))
            ->assertHeader('Location', route('api.runs.show', $run));
        $this->assertSame(RunTrigger::Api, $run->trigger);
        Queue::assertPushed(ExecuteRun::class);
    }

    public function test_a_second_start_while_active_is_409_with_the_active_run(): void
    {
        $this->target();
        $first = $this->start()->json('id');

        $this->start()->assertStatus(409)->assertJsonPath('id', $first)->assertJsonPath('status', 'QUEUED');
        $this->assertSame(1, Run::count());
    }

    public function test_production_is_refused_unless_allowed(): void
    {
        [, $environment] = $this->target('production');
        $this->assertFalse($environment->allow_automation);

        $this->start()->assertForbidden()->assertJsonPath('message', fn ($m) => str_contains($m, '(production)'));
        $this->assertSame(0, Run::count());

        $environment->update(['allow_automation' => true]);
        $this->start()->assertStatus(202);
    }

    public function test_a_non_production_environment_with_automation_off_is_refused(): void
    {
        [, $environment] = $this->target();
        $environment->update(['allow_automation' => false]);

        $this->start()->assertForbidden();
    }

    public function test_unknown_slugs_are_422_naming_the_field(): void
    {
        $this->target();
        Environment::factory()->create(['slug' => 'other-env']); // another project's

        $this->start(['project' => 'nope', 'environment' => 'staging', 'collection' => 'smoke'])
            ->assertUnprocessable()->assertJsonValidationErrors(['project'])->assertJsonMissingValidationErrors(['environment']);
        $this->start(['project' => 'demo-api', 'environment' => 'other-env', 'collection' => 'nope'])
            ->assertUnprocessable()->assertJsonValidationErrors(['environment', 'collection']);
        $this->start([])->assertUnprocessable()->assertJsonValidationErrors(['project', 'environment', 'collection']);
        $this->assertSame(0, Run::count());
    }

    public function test_status_of_a_queued_run(): void
    {
        $run = Run::factory()->create();

        $this->api()->getJson('/api/v1/runs/'.$run->id)->assertOk()
            ->assertJsonPath('status', 'QUEUED')
            ->assertJsonPath('finished', false)
            ->assertJsonPath('summary', ['requests' => null, 'assertions' => null, 'passed' => null, 'failed' => null])
            ->assertJsonPath('error', null)
            ->assertJsonPath('failures', [])
            ->assertJsonPath('web_url', route('runs.show', $run));
    }

    public function test_status_of_a_failed_run_lists_the_failing_assertions(): void
    {
        $run = Run::factory()->failed(assertions: 3, failures: 1)->create();
        RunResult::factory()->for($run)->create(['position' => 0, 'assertion' => 'ok one']);
        RunResult::factory()->failed()->for($run)->create(['position' => 1, 'item_name' => 'Create order', 'method' => 'POST', 'assertion' => 'Status code is 201']);

        $this->api()->getJson('/api/v1/runs/'.$run->id)->assertOk()
            ->assertJsonPath('status', 'FAIL')
            ->assertJsonPath('finished', true)
            ->assertJsonPath('summary', ['requests' => 3, 'assertions' => 3, 'passed' => 2, 'failed' => 1])
            ->assertJsonCount(1, 'failures')
            ->assertJsonPath('failures.0', [
                'request' => 'Create order', 'method' => 'POST', 'url' => RunResult::where('passed', false)->value('url'),
                'response_code' => 500, 'assertion' => 'Status code is 201',
                'message' => 'expected response to have status code 200 but got 500',
            ]);
    }

    public function test_status_of_an_errored_run_and_no_tests_flag(): void
    {
        $error = Run::factory()->create(['status' => RunStatus::Error, 'error_code' => 'request_error', 'error_message' => 'Nowhere: getaddrinfo ENOTFOUND x.invalid']);
        $this->api()->getJson('/api/v1/runs/'.$error->id)
            ->assertJsonPath('error', ['code' => 'request_error', 'message' => 'Nowhere: getaddrinfo ENOTFOUND x.invalid']);

        $noTests = Run::factory()->passed(0)->create();
        $this->api()->getJson('/api/v1/runs/'.$noTests->id)->assertJsonPath('status', 'PASS')->assertJsonPath('no_tests', true);
    }

    public function test_history_survives_deleting_the_project(): void
    {
        $run = Run::factory()->passed()->create();
        $name = $run->project_name;
        $run->project->delete();

        $this->api()->getJson('/api/v1/runs/'.$run->id)->assertOk()->assertJsonPath('project', $name);
    }

    public function test_unknown_or_malformed_run_ids_are_a_plain_404(): void
    {
        $this->api()->getJson('/api/v1/runs/01m4kqj7bdntf4zbnpfdvpfm7e')->assertNotFound()->assertExactJson(['message' => 'Not Found']);
        $this->api()->getJson('/api/v1/runs/not-a-ulid')->assertNotFound()->assertExactJson(['message' => 'Not Found']);
    }
}
