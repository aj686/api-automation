<?php

namespace Tests\Feature\Models;

use App\Enums\RunStatus;
use App\Models\Collection;
use App\Models\Environment;
use App\Models\EnvironmentVariable;
use App\Models\Project;
use App\Models\Run;
use App\Models\RunResult;
use App\Models\WorkerHeartbeat;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class SchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_all_phase_4_tables_exist(): void
    {
        foreach (['projects', 'environments', 'environment_variables', 'collections', 'runs', 'run_results', 'worker_heartbeats'] as $table) {
            $this->assertTrue(Schema::hasTable($table), $table);
        }
    }

    public function test_every_factory_creates_a_valid_row(): void
    {
        $result = RunResult::factory()->create();

        $this->assertSame(1, Project::count());
        $this->assertSame(1, Environment::count());
        $this->assertSame(1, Collection::count());
        $this->assertSame(1, Run::count());
        $this->assertNotNull($result->run);

        EnvironmentVariable::factory()->secret()->create();
        $this->assertSame(1, EnvironmentVariable::count());
    }

    public function test_project_slug_is_generated_and_unique(): void
    {
        $project = Project::create(['name' => 'Finance API']);

        $this->assertSame('finance-api', $project->slug);

        $this->expectException(QueryException::class);
        Project::create(['name' => 'Finance API v2', 'slug' => 'finance-api']);
    }

    public function test_environment_slug_is_unique_per_project_only(): void
    {
        [$a, $b] = Project::factory()->count(2)->create();

        $a->environments()->create(['name' => 'Staging', 'type' => 'staging']);
        $b->environments()->create(['name' => 'Staging', 'type' => 'staging']);
        $this->assertSame(2, Environment::where('slug', 'staging')->count());

        $this->expectException(QueryException::class);
        $a->environments()->create(['name' => 'Staging', 'type' => 'staging']);
    }

    public function test_production_is_excluded_from_automation_by_default(): void
    {
        $project = Project::factory()->create();

        $production = $project->environments()->create(['name' => 'Live', 'type' => 'production']);
        $staging = $project->environments()->create(['name' => 'Staging', 'type' => 'staging']);
        $allowed = $project->environments()->create(['name' => 'Live 2', 'type' => 'production', 'allow_automation' => true]);

        $this->assertFalse($production->fresh()->allow_automation);
        $this->assertTrue($staging->fresh()->allow_automation);
        $this->assertTrue($allowed->fresh()->allow_automation, 'an explicit choice must be kept');
    }

    public function test_variable_values_are_encrypted_at_rest_and_never_serialised(): void
    {
        $secret = EnvironmentVariable::factory()->secret()->create(['value' => 'YOUR_API_KEY']);
        $config = EnvironmentVariable::factory()->create(['key' => 'base_url', 'value' => 'https://api.example.invalid']);

        foreach ([$secret, $config] as $variable) {
            $raw = DB::table('environment_variables')->where('id', $variable->id)->value('value');

            $this->assertNotSame($variable->value, $raw, 'stored as ciphertext');
            $this->assertStringNotContainsString($variable->value, $raw);
            $this->assertSame($variable->value, $variable->fresh()->value, 'decrypts on read');
            $this->assertArrayNotHasKey('value', $variable->toArray());
            $this->assertStringNotContainsString($variable->value, $variable->toJson());
        }
    }

    public function test_variable_key_is_unique_per_environment(): void
    {
        $variable = EnvironmentVariable::factory()->create(['key' => 'base_url']);

        $this->expectException(QueryException::class);
        EnvironmentVariable::factory()->create(['key' => 'base_url', 'environment_id' => $variable->environment_id]);
    }

    public function test_run_history_survives_deleting_its_project(): void
    {
        $run = Run::factory()->passed()->create();
        RunResult::factory()->for($run)->create();
        $names = $run->only('project_name', 'environment_name', 'collection_name');

        $run->project->delete();

        $this->assertSame(0, Environment::count(), 'environments cascade with the project');
        $this->assertSame(0, Collection::count(), 'collections cascade with the project');

        $run->refresh();
        $this->assertNull($run->project_id);
        $this->assertNull($run->environment_id);
        $this->assertNull($run->collection_id);
        $this->assertSame($names, $run->only('project_name', 'environment_name', 'collection_name'));
        $this->assertSame(1, $run->results()->count());
    }

    public function test_deleting_a_run_deletes_its_results(): void
    {
        $run = Run::factory()->create();
        RunResult::factory()->count(3)->for($run)->create();

        $run->delete();

        $this->assertSame(0, RunResult::count());
    }

    public function test_runs_use_ulids_and_unknown_counts_stay_null(): void
    {
        $run = Run::factory()->create()->fresh();

        // HasUlids stores ULIDs lower-cased (Eloquent\Concerns\HasUlids::newUniqueId).
        $this->assertMatchesRegularExpression('/^[0-9a-hjkmnp-tv-z]{26}$/', $run->id);
        $this->assertSame(RunStatus::Queued, $run->status);

        // NULL means unknown, never zero (plan section 4).
        $this->assertNull($run->total_assertions);
        $this->assertNull($run->failed_assertions);
        $this->assertFalse($run->hasNoTests());
    }

    public function test_a_pass_with_zero_assertions_is_flagged(): void
    {
        $this->assertTrue(Run::factory()->passed(0)->create()->hasNoTests());
        $this->assertFalse(Run::factory()->passed(3)->create()->hasNoTests());
        $this->assertFalse(Run::factory()->failed()->create()->hasNoTests());
    }

    public function test_run_results_come_back_in_report_order(): void
    {
        $run = Run::factory()->create();
        foreach ([2, 0, 1] as $position) {
            RunResult::factory()->for($run)->create(['position' => $position]);
        }

        $this->assertSame([0, 1, 2], $run->results->pluck('position')->all());
    }

    public function test_worker_heartbeat_is_unique_per_worker(): void
    {
        WorkerHeartbeat::create(['worker' => 'api-automation-worker', 'beat_at' => now()]);

        $this->expectException(QueryException::class);
        WorkerHeartbeat::create(['worker' => 'api-automation-worker', 'beat_at' => now()]);
    }
}
