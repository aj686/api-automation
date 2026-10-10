<?php

namespace App\Services\Postman;

/**
 * The argument list for one run, as an array — never a shell string (CLAUDE.md
 * hard rules). Every flag was verified against Postman CLI 1.71.0 and is
 * exercised by scripts/verify-postman-cli.sh (decisions.md D-022).
 */
class RunCommandBuilder
{
    /**
     * @return list<string>
     */
    public function build(string $collectionPath, string $environmentPath, string $workingDirectory, string $reportPath, int $requestTimeoutMs): array
    {
        return [
            // Own process group, so cancel/timeout can stop the CLI's child
            // process too: a signal to the `postman` wrapper alone is ignored,
            // and SIGKILL to it orphans the real CLI (D-022).
            'setsid',
            config('automation.postman_cli_path'),
            'collection', 'run', $collectionPath,
            '-e', $environmentPath,
            '--working-dir', $workingDirectory,
            '--no-insecure-file-read',
            '--no-report-events',
            '-r', 'cli,json',
            '--reporter-json-export', $reportPath,
            '--reporter-json-omitAllHeadersAndBody',
            '--disable-unicode',
            '--timeout-request', (string) $requestTimeoutMs,
        ];
    }
}
