<?php

namespace App\Services\Postman;

use App\Enums\RunStatus;
use App\Exceptions\ImportException;
use App\Models\Environment;
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
        private readonly VariableCapture $capture,
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

        $options = $run->runOptions();
        if ($options->items !== []) {
            $missing = $this->missingItems($collectionPath, $options->items);
            if ($missing !== []) {
                return $this->error('items_missing', 'The collection changed after the run was started: '.Str::plural('selected request', count($missing), true).' ('.implode(', ', $missing).') no longer '.(count($missing) === 1 ? 'exists' : 'exist').'. Start the run again.');
            }
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
            $written = $this->writeEnvironment($environmentPath, $environment->name, $variables, $run);

            if ($options->keepVariables) {
                // A copy for this run only; the stored file is never changed (D-039).
                $runCollectionPath = $directory.'/collection.json';
                file_put_contents($runCollectionPath, $this->capture->inject((string) file_get_contents($collectionPath)), LOCK_EX);
                $collectionPath = $runCollectionPath;
            }

            $command = $this->commands->build($collectionPath, $environmentPath, $directory, $reportPath, config('automation.request_timeout_ms'), $options->itemIds(), $options->bail);
            $process = Process::path($directory)->forever()->start($command);
            // Read once: Symfony returns null as soon as the process has exited,
            // which a CLI failing instantly (broken collection) can do before this line.
            $pid = $process->id();
            $run->update(['pid' => $pid, 'cli_version' => $this->cliVersion()]);

            $outcome = $this->supervise($process, $pid, $run, $cancelRequested);
            $output = $process->output().$process->errorOutput();
            $kept = null;
            if ($outcome === null && $options->keepVariables && ($captured = $this->capture->extract($output)) !== null) {
                [$kept, $newSecrets] = $this->keepVariables($environment, $captured, $written);
                $redactor = new Redactor([...$variables->where('is_secret', true)->pluck('value'), ...$newSecrets]);
            }
            $log = $this->log($this->capture->strip($output), $redactor);

            if ($outcome !== null) {
                return ['status' => $outcome['status'], 'attributes' => [...$outcome['attributes'], 'log' => $log], 'results' => [], 'requests' => []];
            }

            $this->reap($process);
            try {
                $exitCode = $process->wait()->exitCode() ?? -1;
            } catch (ProcessSignaledException $e) {
                // Killed from outside the app (e.g. out of memory).
                $error = $this->error('cli_killed', 'Postman CLI was killed by signal '.$e->getSignal().' before it finished.');

                return [...$error, 'attributes' => [...$error['attributes'], 'log' => $log]];
            }
            [$report, $tooLarge] = $this->readReport($reportPath, $directory);
            if ($tooLarge) {
                $error = $this->error('report_too_large', 'The Postman CLI report is too large to read without the Node compaction step (scripts/compact-postman-report.mjs). Check that Node is available in the runner container (AUTOMATION_NODE_PATH).');

                return [...$error, 'attributes' => [...$error['attributes'], 'exit_code' => $exitCode, 'log' => $log]];
            }
            $parsed = $this->parser->parse($report, $exitCode, $redactor);

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
                    'kept_variables' => $kept,
                ],
                'results' => $parsed->results,
                'requests' => $parsed->requests,
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
    private function supervise(InvokedProcess $process, ?int $pid, Run $run, Closure $cancelRequested): ?array
    {
        // Already finished before its pid could be read: nothing left to watch or stop.
        if ($pid === null) {
            return null;
        }

        $group = new ProcessGroup($pid);
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

    /** Without the Node step, PHP reads the raw report only below this size (D-037). */
    private const MAX_RAW_REPORT_BYTES = 8_000_000;

    /**
     * The report as PHP should read it. Postman CLI stores bodies as byte
     * arrays (a 1 MB body ≈ 20 MB of JSON, ≈ 70 MB to decode), so the Node
     * script turns them into text, cut at the payload limit, first.
     *
     * @return array{?string, bool} report JSON (null if none), too large to read
     */
    private function readReport(string $reportPath, string $directory): array
    {
        if (! is_file($reportPath)) {
            return [null, false];
        }

        $node = config('automation.node_path');
        $nodeFound = str_contains($node, '/') ? is_executable($node) : (new ExecutableFinder)->find($node) !== null;

        if ($nodeFound) {
            $compact = $directory.'/report.compact.json';
            $result = Process::timeout(120)->run([
                $node, base_path('scripts/compact-postman-report.mjs'),
                $reportPath, $compact, (string) config('automation.payload_max_bytes'),
            ]);
            if ($result->successful() && is_file($compact)) {
                return [file_get_contents($compact) ?: null, false];
            }
        }

        if (filesize($reportPath) > self::MAX_RAW_REPORT_BYTES) {
            return [null, true];
        }

        return [file_get_contents($reportPath) ?: null, false];
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
    /**
     * Names of selected requests that are not in the file any more. Postman
     * CLI would only say "Unable to find request or folder" and write no report.
     *
     * @param  list<array{id: string, name: string}>  $items
     * @return list<string>
     */
    private function missingItems(string $collectionPath, array $items): array
    {
        try {
            $document = CollectionDocument::fromJson((string) file_get_contents($collectionPath));
        } catch (ImportException) {
            return array_column($items, 'name');
        }

        return array_values(array_map(
            fn (array $item) => $item['name'] !== '' ? $item['name'] : $item['id'],
            array_filter($items, fn (array $item) => ! $document->isRequest($item['id'])),
        ));
    }

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
    /**
     * @return array<string, string>  key => value as written, for "Keep variable values"
     */
    private function writeEnvironment(string $path, string $name, $variables, Run $run): array
    {
        $values = $variables->map(fn ($variable) => [
            'key' => $variable->key,
            'value' => (string) $variable->value,
            'type' => $variable->is_secret ? 'secret' : 'default',
            'enabled' => true,
        ])->values()->all();

        // Provided to every run, unless the environment defines them itself:
        // outbound tests filter captures with ?since={{run_started_at}} (loophole 44).
        $provided = [
            'run_id' => $run->id,
            'run_started_at' => ($run->started_at ?? now())->toIso8601ZuluString(),
        ];
        foreach ($provided as $key => $value) {
            if (! $variables->contains('key', $key)) {
                $values[] = ['key' => $key, 'value' => $value, 'type' => 'default', 'enabled' => true];
            }
        }

        $json = json_encode([
            'name' => $name,
            'values' => $values,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);

        $umask = umask(0077);
        try {
            file_put_contents($path, $json, LOCK_EX);
        } finally {
            umask($umask);
        }
        @chmod($path, 0600);

        return array_column($values, 'value', 'key');
    }

    /**
     * Saves what the run's scripts changed or added to the environment
     * (Postman's "Keep variable values", D-039). Keys removed by a script are
     * left alone; run_id and run_started_at are the app's own unless the
     * environment defines them. Values are encrypted like every other.
     *
     * @param  array<string, string>  $captured  the environment after the run
     * @param  array<string, string>  $written  what the run started with
     * @return array{0: array{saved: list<string>, skipped: list<string>}|null, 1: list<string>}  names; new secret values for the redactor
     */
    private function keepVariables(Environment $environment, array $captured, array $written): array
    {
        $existing = $environment->variables()->get()->keyBy('key');
        $saved = $skipped = $newSecrets = [];

        foreach ($captured as $key => $value) {
            if (array_key_exists($key, $written) && $written[$key] === $value) {
                continue;
            }
            if (in_array($key, ['run_id', 'run_started_at'], true) && ! $existing->has($key)) {
                continue;
            }
            if (! preg_match(EnvironmentVariable::KEY_PATTERN, $key) || mb_strlen($value) > EnvironmentVariable::MAX_VALUE_LENGTH) {
                $skipped[] = Str::limit($key, 100, '');

                continue;
            }

            $variable = $existing->get($key);
            if ($variable) {
                $variable->update(['value' => $value]);
            } else {
                $variable = $environment->variables()->create([
                    'key' => $key,
                    'value' => $value,
                    'is_secret' => (bool) preg_match(EnvironmentVariable::SECRET_KEY_PATTERN, $key),
                ]);
            }
            if ($variable->is_secret) {
                $newSecrets[] = $value;
            }
            $saved[] = $key;
        }

        return [$saved || $skipped ? ['saved' => $saved, 'skipped' => $skipped] : null, $newSecrets];
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
        return ['status' => RunStatus::Error, 'attributes' => ['error_code' => $code, 'error_message' => $message], 'results' => [], 'requests' => []];
    }
}
