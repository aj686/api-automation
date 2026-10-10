<?php

namespace Tests\Feature\Runner;

use App\Enums\RunStatus;
use App\Jobs\ExecuteRun;
use App\Models\Environment;
use App\Models\Run;
use App\Models\WorkerHeartbeat;
use App\Services\CollectionImporter;
use App\Services\Postman\PostmanCli;
use App\Services\Postman\ProcessGroup;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * The real runner code against tests/fixtures/fake-postman (plan phase 9 gate).
 */
class ExecuteRunTest extends TestCase
{
    use RefreshDatabase;

    private string $runsDirectory;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake(CollectionImporter::DISK);
        $this->runsDirectory = storage_path('framework/testing/runs-'.getmypid());
        config([
            'automation.postman_cli_path' => base_path('tests/fixtures/fake-postman/postman'),
            'automation.runs_directory' => $this->runsDirectory,
            'automation.stop_grace_seconds' => 1,
        ]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->runsDirectory);
        parent::tearDown();
    }

    private function queuedRun(string $mode, array $variables = []): Run
    {
        $environment = Environment::factory()->create();
        foreach ($variables as $key => [$value, $secret]) {
            $environment->variables()->create(['key' => $key, 'value' => $value, 'is_secret' => $secret]);
        }

        $collection = app(CollectionImporter::class)->store($environment->project, json_encode([
            'info' => ['name' => 'Fake '.$mode, 'schema' => 'https://schema.getpostman.com/json/collection/v2.1.0/collection.json'],
            'item' => [],
            'fake_mode' => $mode,
        ]), 'fake.json');

        return Run::factory()->create([
            'project_id' => $environment->project_id,
            'environment_id' => $environment->id,
            'collection_id' => $collection->id,
        ]);
    }

    private function execute(Run $run): Run
    {
        (new ExecuteRun($run->id))->handle(app(PostmanCli::class));

        return $run->fresh();
    }

    public function test_a_passing_run(): void
    {
        $run = $this->execute($this->queuedRun('pass', ['api_key' => ['YOUR_API_KEY', true]]));

        $this->assertSame(RunStatus::Pass, $run->status);
        $this->assertSame([0, 2, 2, 0], [$run->exit_code, $run->total_assertions, $run->passed_assertions, $run->failed_assertions]);
        $this->assertSame('1.71.0', $run->cli_version);
        $this->assertNotNull($run->started_at);
        $this->assertNotNull($run->duration_ms);
        $this->assertNull($run->pid);
        $this->assertSame(2, $run->results()->count());
        $this->assertStringContainsString('status is 200', $run->log);
        $this->assertStringNotContainsString('YOUR_API_KEY', $run->log.$run->results()->get()->toJson(), 'redacted');
        $this->assertSame([], File::glob($this->runsDirectory.'/*'), 'temp files deleted');
    }

    public function test_failing_and_erroring_runs(): void
    {
        $this->assertSame(RunStatus::Fail, $this->execute($this->queuedRun('fail'))->status);

        $unreachable = $this->execute($this->queuedRun('unreachable'));
        $this->assertSame(RunStatus::Error, $unreachable->status);
        $this->assertSame('request_error', $unreachable->error_code);

        $noReport = $this->execute($this->queuedRun('noreport'));
        $this->assertSame('report_missing', $noReport->error_code);
        $this->assertStringContainsString('collection could not be loaded', $noReport->log);
        $this->assertNull($noReport->total_assertions, 'unknown, not zero');
    }

    public function test_the_environment_file_is_0600_has_enabled_variables_only_and_secrets_never_reach_the_log(): void
    {
        $run = $this->queuedRun('echoenv', ['base_url' => ['https://api.example.invalid', false], 'api_key' => ['YOUR_API_KEY', true]]);
        $run->environment->variables()->create(['key' => 'old_token', 'value' => 'YOUR_OLD_TOKEN', 'is_secret' => true, 'enabled' => false]);

        $run = $this->execute($run);

        $this->assertStringContainsString('env-mode=600', $run->log);
        $this->assertStringContainsString('"key":"base_url","value":"https://api.example.invalid"', $run->log);
        $this->assertStringContainsString('"key":"api_key","value":"********","type":"secret"', $run->log);
        $this->assertStringNotContainsString('YOUR_API_KEY', $run->log);
        $this->assertStringNotContainsString('old_token', $run->log, 'disabled variables are not written');
    }

    public function test_cancel_stops_the_whole_process_group(): void
    {
        $run = $this->queuedRun('hang');
        $run->update(['status' => RunStatus::Running, 'started_at' => now()]);
        $askedAt = microtime(true) + 1;
        $aliveBeforeCancel = null;

        $outcome = app(PostmanCli::class)->execute($run, function () use ($run, $askedAt, &$aliveBeforeCancel) {
            // Proves pid == process group id (setsid exec'd in place); otherwise
            // alive() would be false from the start and a cancel could not be confirmed.
            $aliveBeforeCancel ??= (new ProcessGroup($run->fresh()->pid))->alive();

            return microtime(true) >= $askedAt;
        });

        $this->assertTrue($aliveBeforeCancel, 'the group was visible while running');
        $this->assertSame(RunStatus::Cancelled, $outcome['status']);
        $this->assertFalse((new ProcessGroup($run->fresh()->pid))->alive());
        $this->assertLessThan(5, microtime(true) - $askedAt, 'stopped promptly');
    }

    public function test_sigkill_is_used_when_sigterm_is_ignored(): void
    {
        $run = $this->queuedRun('stubborn');
        $run->update(['status' => RunStatus::Running, 'started_at' => now()]);

        $outcome = app(PostmanCli::class)->execute($run, fn () => true);

        $this->assertSame(RunStatus::Cancelled, $outcome['status']);
        $this->assertFalse((new ProcessGroup($run->fresh()->pid))->alive());
    }

    public function test_timeout(): void
    {
        $run = $this->queuedRun('hang');
        $run->update(['timeout_seconds' => 1]);

        $run = $this->execute($run);

        $this->assertSame(RunStatus::Timeout, $run->status);
        $this->assertSame('timeout', $run->error_code);
        $this->assertNull($run->total_assertions);
    }

    public function test_a_long_run_keeps_the_heartbeat_fresh(): void
    {
        WorkerHeartbeat::query()->delete();
        $run = $this->queuedRun('hang');
        $run->update(['timeout_seconds' => 1]);

        $this->execute($run);

        $this->assertTrue(WorkerHeartbeat::forConfiguredWorker()?->isFresh());
    }

    public function test_cancel_before_start_never_runs_the_cli(): void
    {
        $run = $this->queuedRun('hang');
        $run->update(['cancel_requested_at' => now()]);

        $run = $this->execute($run);

        $this->assertSame(RunStatus::Cancelled, $run->status);
        $this->assertNull($run->started_at);
        $this->assertNull($run->cli_version);
    }

    public function test_missing_cli_or_collection_file_is_an_error_with_a_clear_message(): void
    {
        config(['automation.postman_cli_path' => '/nonexistent/postman']);
        $run = $this->execute($this->queuedRun('pass'));
        $this->assertSame('cli_missing', $run->error_code);
        $this->assertStringContainsString('Postman CLI could not be started', $run->error_message);

        config(['automation.postman_cli_path' => base_path('tests/fixtures/fake-postman/postman')]);
        $run = $this->queuedRun('pass');
        Storage::disk('local')->delete($run->collection->stored_path);
        $this->assertSame('collection_missing', $this->execute($run)->error_code);
    }

    public function test_a_run_that_is_not_queued_is_left_alone(): void
    {
        $run = $this->queuedRun('pass');
        $run->update(['status' => RunStatus::Pass]);

        $this->assertNull($this->execute($run)->cli_version);
    }

    public function test_a_crashed_job_marks_the_run_error(): void
    {
        $run = $this->queuedRun('pass');
        $run->update(['status' => RunStatus::Running]);

        (new ExecuteRun($run->id))->failed(new \RuntimeException('boom'));

        $this->assertSame('worker_failure', $run->fresh()->error_code);
    }
}
