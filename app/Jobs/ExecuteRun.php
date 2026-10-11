<?php

namespace App\Jobs;

use App\Enums\RunStatus;
use App\Models\Run;
use App\Models\RunRequest;
use App\Services\Postman\PostmanCli;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Arr;
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

        $this->finish($run, $outcome['status'], $outcome['attributes'], $outcome['results'], $outcome['requests'] ?? []);
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
     * @param  list<array<string, mixed>>  $requests
     */
    private function finish(Run $run, RunStatus $status, array $attributes, array $results, array $requests = []): void
    {
        DB::transaction(function () use ($run, $status, $attributes, $results, $requests) {
            $run->results()->delete();
            $run->requests()->delete();

            // Request cards first, then each assertion row linked to its card (D-037).
            $requestIds = [];
            foreach ($requests as $index => $request) {
                $requestIds[$index] = $run->requests()->create($request)->id;
            }
            $run->results()->createMany(array_map(fn (array $row) => [
                ...Arr::except($row, 'request_index'),
                'run_request_id' => $requestIds[$row['request_index'] ?? -1] ?? null,
            ], $results));

            $finishedAt = now();
            $run->update([
                ...$attributes,
                'status' => $status,
                'pid' => null,
                'finished_at' => $finishedAt,
                'duration_ms' => $run->started_at ? (int) $run->started_at->diffInMilliseconds($finishedAt) : null,
            ]);
        });

        self::purgeOldPayloads();
    }

    /**
     * Deletes headers and bodies of runs older than the retention period, keeping
     * the request line, status, timing and test results (D-037). Runs whenever a
     * run finishes, instead of on a scheduler.
     */
    public static function purgeOldPayloads(): void
    {
        RunRequest::query()
            ->whereNull('payload_purged_at')
            ->whereIn('run_id', Run::query()->select('id')->where('finished_at', '<', now()->subDays(config('automation.payload_retention_days'))))
            ->update([
                'request_headers' => null, 'request_body' => null,
                'response_headers' => null, 'response_body' => null,
                'payload_purged_at' => now(),
            ]);
    }
}
