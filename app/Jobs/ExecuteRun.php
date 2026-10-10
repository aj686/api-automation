<?php

namespace App\Jobs;

use App\Enums\RunStatus;
use App\Models\Run;
use App\Services\Postman\PostmanCli;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Runs in the api-automation-worker container, the only place Postman CLI
 * exists. One attempt only: a retried run would hit the target API twice.
 */
class ExecuteRun implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public function __construct(public readonly string $runId) {}

    public function handle(PostmanCli $cli): void
    {
        $run = Run::find($this->runId);

        // Gone, already handled, or picked up twice: nothing to do.
        if (! $run || $run->status !== RunStatus::Queued) {
            return;
        }

        if ($run->cancel_requested_at !== null) {
            $run->update(['status' => RunStatus::Cancelled, 'finished_at' => now()]);

            return;
        }

        $run->update(['status' => RunStatus::Running, 'started_at' => now()]);

        $outcome = $cli->execute($run, fn () => Run::whereKey($run->id)->whereNotNull('cancel_requested_at')->exists());

        $this->finish($run, $outcome['status'], $outcome['attributes'], $outcome['results']);
    }

    /**
     * Called by the queue when handle() throws or the job times out.
     */
    public function failed(?Throwable $exception): void
    {
        $run = Run::find($this->runId);

        if ($run && $run->status->isActive()) {
            $this->finish($run, RunStatus::Error, [
                'error_code' => 'worker_failure',
                'error_message' => 'The runner failed while executing this run: '.($exception ? class_basename($exception) : 'unknown error').'. Check the runner container log.',
            ], []);
        }
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @param  list<array<string, mixed>>  $results
     */
    private function finish(Run $run, RunStatus $status, array $attributes, array $results): void
    {
        DB::transaction(function () use ($run, $status, $attributes, $results) {
            $run->results()->delete();
            $run->results()->createMany($results);

            $finishedAt = now();
            $run->update([
                ...$attributes,
                'status' => $status,
                'pid' => null,
                'finished_at' => $finishedAt,
                'duration_ms' => $run->started_at ? (int) $run->started_at->diffInMilliseconds($finishedAt) : null,
            ]);
        });
    }
}
