<?php

namespace Tests\Feature\Runner;

use App\Enums\RunStatus;
use App\Enums\RunTrigger;
use App\Exceptions\RunAlreadyActive;
use App\Jobs\ExecuteRun;
use App\Livewire\ProjectShow;
use App\Models\Collection;
use App\Models\Environment;
use App\Models\Run;
use App\Services\RunService;
use App\Support\StaleRunRecovery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\TestCase;

class RunServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
    }

    /**
     * @return array{Environment, Collection}
     */
    private function target(string $type = 'staging'): array
    {
        $environment = Environment::factory()->create(['type' => $type]);

        return [$environment, Collection::factory()->for($environment->project)->create()];
    }

    public function test_start_queues_a_run_with_snapshots(): void
    {
        [$environment, $collection] = $this->target();

        $run = app(RunService::class)->start($environment, $collection, RunTrigger::Manual);

        $this->assertSame(RunStatus::Queued, $run->status);
        $this->assertSame($environment->project->name, $run->project_name);
        $this->assertSame($environment->name, $run->environment_name);
        $this->assertSame('staging', $run->environment_type);
        $this->assertSame($collection->name, $run->collection_name);
        $this->assertSame(300, $run->timeout_seconds);
        Queue::assertPushed(ExecuteRun::class, fn (ExecuteRun $job) => $job->runId === $run->id);
    }

    public function test_a_second_start_while_active_is_refused(): void
    {
        [$environment, $collection] = $this->target();
        $first = app(RunService::class)->start($environment, $collection, RunTrigger::Manual);

        try {
            app(RunService::class)->start($environment, $collection, RunTrigger::Api);
            $this->fail('expected RunAlreadyActive');
        } catch (RunAlreadyActive $e) {
            $this->assertTrue($e->run->is($first));
        }

        $first->update(['status' => RunStatus::Pass]);
        $this->assertSame(RunStatus::Queued, app(RunService::class)->start($environment, $collection, RunTrigger::Manual)->status);
    }

    public function test_cross_project_targets_are_refused(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        app(RunService::class)->start(Environment::factory()->create(), Collection::factory()->create(), RunTrigger::Manual);
    }

    public function test_cancel_only_requests_a_stop(): void
    {
        $run = Run::factory()->create();

        app(RunService::class)->cancel($run);

        $this->assertNotNull($run->fresh()->cancel_requested_at);
        $this->assertSame(RunStatus::Queued, $run->fresh()->status, 'the worker decides once the process is gone');
    }

    public function test_run_panel_starts_and_cancels_runs(): void
    {
        [$environment, $collection] = $this->target();

        $page = Livewire::test(ProjectShow::class, ['project' => $environment->project])
            ->assertSet('runEnvironmentId', $environment->id)
            ->assertSet('runCollectionId', $collection->id)
            ->call('startRun')
            ->assertHasNoErrors()
            ->assertSee('QUEUED')
            ->assertSee('Cancel');

        $run = Run::sole();
        $page->call('startRun')->assertHasErrors('run');

        $page->call('cancelRun', $run->id)->assertSee('Stopping');
        $this->assertNotNull($run->fresh()->cancel_requested_at);
    }

    public function test_production_needs_the_typed_name(): void
    {
        [$environment] = $this->target('production');
        $environment->update(['name' => 'Live']);

        $page = Livewire::test(ProjectShow::class, ['project' => $environment->project])
            ->assertSee('tests may modify real data')
            ->call('startRun')
            ->assertHasErrors('confirmProduction');
        $this->assertSame(0, Run::count());

        $page->set('confirmProduction', 'Live')->call('startRun')->assertHasNoErrors();
        $this->assertSame(1, Run::count());
    }

    public function test_the_panel_explains_what_is_missing(): void
    {
        $environment = Environment::factory()->create();

        Livewire::test(ProjectShow::class, ['project' => $environment->project])
            ->assertSee('needs at least one');
    }

    public function test_worker_start_recovers_interrupted_runs_once(): void
    {
        $running = Run::factory()->create(['status' => RunStatus::Running, 'pid' => 123]);
        $queued = Run::factory()->create();
        $directory = config('automation.runs_directory').'/leftover';
        File::ensureDirectoryExists($directory);

        $recovery = new StaleRunRecovery;
        $recovery->runOnce();

        $this->assertSame(RunStatus::Error, $running->fresh()->status);
        $this->assertSame('worker_restarted', $running->fresh()->error_code);
        $this->assertSame(RunStatus::Queued, $queued->fresh()->status);
        $this->assertDirectoryDoesNotExist($directory);

        $later = Run::factory()->create(['status' => RunStatus::Running]);
        $recovery->runOnce();
        $this->assertSame(RunStatus::Running, $later->fresh()->status, 'only on worker start');
    }
}
