<?php

namespace App\Services\Postman;

use App\Enums\RunStatus;
use App\Models\EnvironmentVariable;
use App\Models\Run;
use App\Models\WorkerHeartbeat;
use App\Services\CollectionImporter;
use App\Services\Redactor;
use Closure;
use Illuminate\Process\InvokedProcess;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\Process\Exception\ProcessSignaledException;
use Symfony\Component\Process\ExecutableFinder;

/**
 * Executes one run with Postman CLI and returns its final state.
 *
 * Flow (plan section 1): temp environment file (0600) → `postman collection
 * run` in its own process group → poll for cancel / wall-clock timeout →
 * parse the JSON report → redact → delete every temp file in `finally`.
 */
class PostmanCli
{
    private const POLL_MICROSECONDS = 250_000;

    public function __construct(
        private readonly RunCommandBuilder $commands,
        private readonly ReportParser $parser,
    ) {}

    /**
     * @param  Closure(): bool  $cancelRequested  checked about every second while the CLI runs
     * @return array{status: RunStatus, attributes: array<string, mixed>, results: list<array<string, mixed>>}
     */
    public function execute(Run $run, Closure $cancelRequested): array
    {
        $environment = $run->environment;
        $collection = $run->collection;

        if (! $environment || ! $collection) {
            return $this->error('target_missing', 'The environment or collection was deleted before the run started.');
        }

        $collectionPath = $this->collectionPath($collection->stored_path);
        if ($collectionPath === null) {
            return $this->error('collection_missing', 'The stored collection file is missing. Import the collection again.');
        }

        if (! $this->cliAvailable()) {
            return $this->error('cli_missing', 'Postman CLI could not be started. Check that it is installed in the runner container and that POSTMAN_CLI_PATH in .env points to it ('.config('automation.postman_cli_path').').');
        }

        $variables = $environment->variables()->where('enabled', true)->get();
        $redactor = new Redactor($variables->where('is_secret', true)->pluck('value'));

        $directory = rtrim(config('automation.runs_directory'), '/').'/'.$run->id;
        File::ensureDirectoryExists($directory, 0700);
        @chmod($directory, 0700);
        $environmentPath = $directory.'/environment.json';
        $reportPath = $directory.'/report.json';

        try {
            $this->writeEnvironment($environmentPath, $environment->name, $variables);

            $command = $this->commands->build($collectionPath, $environmentPath, $directory, $reportPath, config('automation.request_timeout_ms'));
            $process = Process::path($directory)->forever()->start($command);
            $run->update(['pid' => $process->id(), 'cli_version' => $this->cliVersion()]);

            $outcome = $this->supervise($process, $run, $cancelRequested);
            $log = $this->log($process->output().$process->errorOutput(), $redactor);

            if ($outcome !== null) {
                return ['status' => $outcome['status'], 'attributes' => [...$outcome['attributes'], 'log' => $log], 'results' => []];
            }

            $this->reap($process);
            try {
                $exitCode = $process->wait()->exitCode() ?? -1;
            } catch (ProcessSignaledException $e) {
                // Killed from outside the app (e.g. out of memory).
                $error = $this->error('cli_killed', 'Postman CLI was killed by signal '.$e->getSignal().' before it finished.');

                return [...$error, 'attributes' => [...$error['attributes'], 'log' => $log]];
            }
            $report = is_file($reportPath) ? file_get_contents($reportPath) : null;
            $parsed = $this->parser->parse($report === false ? null : $report, $exitCode, $redactor);

            return [
                'status' => $parsed->status,
                'attributes' => [
                    'exit_code' => $exitCode,
                    'error_code' => $parsed->errorCode,
                    'error_message' => $parsed->errorMessage,
                    'total_requests' => $parsed->totalRequests,
                    'total_assertions' => $parsed->totalAssertions,
                    'passed_assertions' => $parsed->passedAssertions,
                    'failed_assertions' => $parsed->failedAssertions,
                    'log' => $log,
                ],
                'results' => $parsed->results,
            ];
        } finally {
            // Holds every secret of the environment in clear text.
            File::deleteDirectory($directory);
        }
    }

    /**
     * Waits for the CLI, stopping its whole process group on cancel or timeout.
     * Returns null when the CLI finished on its own.
     *
     * @return array{status: RunStatus, attributes: array<string, mixed>}|null
     */
    private function supervise(InvokedProcess $process, Run $run, Closure $cancelRequested): ?array
    {
        $group = new ProcessGroup($process->id());
        $deadline = microtime(true) + $run->timeout_seconds;
        $nextCancelCheck = 0.0;
        $nextBeat = 0.0;

        while ($process->running()) {
            $now = microtime(true);

            // queue:work only beats between jobs; a long run must beat itself (D-014).
            if ($now >= $nextBeat) {
                WorkerHeartbeat::beat(config('automation.worker.name'));
                $nextBeat = $now + config('automation.worker.heartbeat_every_seconds');
            }

            $reason = match (true) {
                $now >= $deadline => RunStatus::Timeout,
                $now >= $nextCancelCheck && $cancelRequested() => RunStatus::Cancelled,
                default => null,
            };
            if ($now >= $nextCancelCheck) {
                $nextCancelCheck = $now + 1;
            }

            if ($reason !== null) {
                return $this->stop($group, $process, $reason, $run);
            }

            usleep(self::POLL_MICROSECONDS);
        }

        // Finished: make sure nothing of the group outlived the wrapper.
        $group->stop(1);

        return null;
    }

    /**
     * @return array{status: RunStatus, attributes: array<string, mixed>}
     */
    private function stop(ProcessGroup $group, InvokedProcess $process, RunStatus $reason, Run $run): array
    {
        $gone = $group->stop(config('automation.stop_grace_seconds'));
        // Not wait(): Symfony throws when a process died of a signal it did not send itself.
        $this->reap($process);

        // Plan section 5: CANCELLED/TIMEOUT only once the process is confirmed gone.
        if (! $gone) {
            return ['status' => RunStatus::Error, 'attributes' => [
                'error_code' => 'process_not_stopped',
                'error_message' => 'The run was asked to stop, but Postman CLI processes were still alive after SIGKILL. Check the runner container.',
            ]];
        }

        return ['status' => $reason, 'attributes' => $reason === RunStatus::Timeout
            ? ['error_code' => 'timeout', 'error_message' => "The run exceeded its {$run->timeout_seconds}-second limit and was stopped."]
            : ['error_code' => null, 'error_message' => null]];
    }

    /**
     * Waits (bounded) until the CLI's own process has exited and been reaped.
     */
    private function reap(InvokedProcess $process): void
    {
        $deadline = microtime(true) + 5;
        while ($process->running() && microtime(true) < $deadline) {
            usleep(50_000);
        }
    }

    /**
     * Resolves the stored path and refuses anything outside the collections
     * directory (plan section 6: realpath-checked).
     */
    private function collectionPath(string $storedPath): ?string
    {
        $root = realpath(Storage::disk(CollectionImporter::DISK)->path(CollectionImporter::DIRECTORY));
        $path = realpath(Storage::disk(CollectionImporter::DISK)->path($storedPath));

        return $root && $path && str_starts_with($path, $root.DIRECTORY_SEPARATOR) && is_file($path) ? $path : null;
    }

    private function cliAvailable(): bool
    {
        $cli = config('automation.postman_cli_path');

        return str_contains($cli, '/') ? is_executable($cli) : (new ExecutableFinder)->find($cli) !== null;
    }

    private function cliVersion(): ?string
    {
        $result = Process::timeout(30)->run([config('automation.postman_cli_path'), '--version']);
        $version = trim(Str::afterLast(trim($result->output()), "\n"));

        return $result->successful() && preg_match('/^\d+\.\d+\.\d+/', $version, $m) ? $m[0] : null;
    }

    /**
     * Postman environment file; only enabled variables, written 0600.
     *
     * @param  Collection<int, EnvironmentVariable>  $variables
     */
    private function writeEnvironment(string $path, string $name, $variables): void
    {
        $json = json_encode([
            'name' => $name,
            'values' => $variables->map(fn ($variable) => [
                'key' => $variable->key,
                'value' => (string) $variable->value,
                'type' => $variable->is_secret ? 'secret' : 'default',
                'enabled' => true,
            ])->values()->all(),
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);

        $umask = umask(0077);
        try {
            file_put_contents($path, $json, LOCK_EX);
        } finally {
            umask($umask);
        }
        @chmod($path, 0600);
    }

    private function log(string $output, Redactor $redactor): ?string
    {
        $output = trim($redactor->redact($output) ?? '');
        $max = config('automation.log_max_bytes');

        if ($output === '') {
            return null;
        }

        return strlen($output) > $max
            ? mb_strcut($output, 0, $max)."\n[log truncated at {$max} bytes]"
            : $output;
    }

    /**
     * @return array{status: RunStatus, attributes: array<string, mixed>, results: list<array<string, mixed>>}
     */
    private function error(string $code, string $message): array
    {
        return ['status' => RunStatus::Error, 'attributes' => ['error_code' => $code, 'error_message' => $message], 'results' => []];
    }
}
