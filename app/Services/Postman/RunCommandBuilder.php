<?php

namespace App\Services\Postman;

/**
 * The argument list for one run, as an array — never a shell string (CLAUDE.md
 * hard rules). Every flag was verified against Postman CLI 1.71.0 and is
 * exercised by scripts/verify-postman-cli.sh (decisions.md D-022, D-038).
 */
class RunCommandBuilder
{
    /**
     * @param  list<string>  $itemIds  request ids in send order; empty = the whole collection
     * @return list<string>
     */
    public function build(string $collectionPath, string $environmentPath, string $workingDirectory, string $reportPath, int $requestTimeoutMs, array $itemIds = [], bool $bail = false): array
    {
        $selection = [];
        // By id: 1.71.0 does not find the "Folder/Request" form its --help
        // offers, and a name picks the first match. Repeated -i keeps order.
        foreach ($itemIds as $id) {
            $selection[] = '-i';
            $selection[] = $id;
        }

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
            // Headers and bodies are kept (D-037): the parser redacts them and
            // scripts/compact-postman-report.mjs trims bodies first.
            '--reporter-json-export', $reportPath,
            '--disable-unicode',
            '--timeout-request', (string) $requestTimeoutMs,
            ...$selection,
            // Stops after the first request with a failed test or an error.
            ...($bail ? ['--bail'] : []),
        ];
    }
}
