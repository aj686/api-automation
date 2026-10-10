<?php

namespace App\Services\Postman;

use App\Enums\RunStatus;
use App\Services\Redactor;
use Illuminate\Support\Str;

/**
 * Turns a Postman CLI 1.71 JSON report (`-r json`, headers and bodies omitted)
 * into a status, counts and result rows.
 *
 * Written against reports captured from the real CLI by
 * scripts/verify-postman-cli.sh (tests/fixtures/postman). The decision order
 * is plan section 5, with the request/script mapping settled by those
 * captures (decisions.md D-023):
 *
 *   no or unreadable report         → ERROR  (report_missing / report_invalid)
 *   run.runError set                → ERROR  (run_error)
 *   a request could not be sent     → ERROR  (request_error: DNS, refused, timed out)
 *   a pre-request/test script threw → ERROR  (script_error)
 *   an assertion failed             → FAIL
 *   exit code ≠ 0 anyway            → ERROR  (unexpected_exit)
 *   otherwise                       → PASS   (zero assertions is flagged NO TESTS by Run::hasNoTests)
 *
 * HTTP status never decides anything by itself: an expected 404 passes.
 */
class ReportParser
{
    private const MAX_MESSAGE = 1000;

    public function parse(?string $json, int $exitCode, Redactor $redactor): ParsedReport
    {
        if ($json === null || trim($json) === '') {
            return new ParsedReport(RunStatus::Error, 'report_missing', 'Postman CLI did not write a report. See the execution log for its error.');
        }

        $data = json_decode($json, true);
        $run = is_array($data) ? ($data['run'] ?? null) : null;
        $summary = is_array($run) ? ($run['summary'] ?? null) : null;

        if (! is_array($summary) || ! isset($run['executions']) || ! is_array($run['executions'])) {
            return new ParsedReport(RunStatus::Error, 'report_invalid', 'The Postman CLI report could not be read. It may come from an unsupported CLI version.');
        }

        $results = $this->results($run['executions'], $redactor);
        $counts = [
            'totalRequests' => $this->int($summary, 'executedRequests', 'executed'),
            'totalAssertions' => $this->int($summary, 'tests', 'executed'),
            'passedAssertions' => $this->int($summary, 'tests', 'passed'),
            'failedAssertions' => $this->int($summary, 'tests', 'failed'),
            'results' => $results,
        ];

        if (! empty($run['runError'])) {
            $message = is_array($run['runError']) ? ($run['runError']['message'] ?? json_encode($run['runError'])) : (string) $run['runError'];

            return new ParsedReport(RunStatus::Error, 'run_error', $this->message($message, $redactor), ...$counts);
        }

        if (($this->int($summary, 'executedRequests', 'errors') ?? 0) > 0) {
            return new ParsedReport(RunStatus::Error, 'request_error',
                $this->firstExecutionError($run['executions'], $redactor) ?? 'A request could not be sent.', ...$counts);
        }

        $scriptErrors = ($this->int($summary, 'prerequestScripts', 'errors') ?? 0) + ($this->int($summary, 'postresponseScripts', 'errors') ?? 0);
        if ($scriptErrors > 0) {
            return new ParsedReport(RunStatus::Error, 'script_error',
                $this->firstExecutionError($run['executions'], $redactor) ?? 'A pre-request or test script failed.', ...$counts);
        }

        if (($counts['failedAssertions'] ?? 0) > 0) {
            return new ParsedReport(RunStatus::Fail, null, null, ...$counts);
        }

        if ($exitCode !== 0) {
            return new ParsedReport(RunStatus::Error, 'unexpected_exit',
                "Postman CLI exited with code {$exitCode} although the report shows no failure.", ...$counts);
        }

        return new ParsedReport(RunStatus::Pass, null, null, ...$counts);
    }

    /**
     * One row per assertion; a request with no assertions still gets a row so
     * it is visible, and a request error is attached to its request's row.
     *
     * @param  array<mixed>  $executions
     * @return list<array{position: int, item_name: string, method: ?string, url: ?string, response_code: ?int, response_time_ms: ?int, assertion: ?string, passed: bool, error_message: ?string}>
     */
    private function results(array $executions, Redactor $redactor): array
    {
        $rows = [];

        foreach ($executions as $execution) {
            if (! is_array($execution)) {
                continue;
            }

            $request = is_array($execution['requestExecuted'] ?? null) ? $execution['requestExecuted'] : [];
            $response = is_array($execution['response'] ?? null) ? $execution['response'] : [];
            $errors = array_values(array_filter((array) ($execution['errors'] ?? []), 'is_array'));
            $executionError = $errors ? $this->message($errors[0]['message'] ?? 'Error', $redactor) : null;

            $base = [
                'item_name' => Str::limit($redactor->redact((string) ($request['name'] ?? 'Unnamed request')), 250),
                'method' => isset($request['method']) ? Str::limit((string) $request['method'], 10, '') : null,
                'url' => $this->url($request['url'] ?? null, $redactor),
                'response_code' => isset($response['code']) && is_int($response['code']) ? $response['code'] : null,
                'response_time_ms' => isset($response['responseTime']) && is_numeric($response['responseTime']) ? (int) $response['responseTime'] : null,
            ];

            $tests = array_values(array_filter((array) ($execution['tests'] ?? []), 'is_array'));

            if ($tests === []) {
                $rows[] = [...$base, 'assertion' => null, 'passed' => $executionError === null, 'error_message' => $executionError];

                continue;
            }

            foreach ($tests as $index => $test) {
                $status = $test['status'] ?? null;
                $error = $test['error']['message'] ?? null;

                $rows[] = [
                    ...$base,
                    'assertion' => Str::limit($redactor->redact((string) ($test['name'] ?? 'Unnamed test')), 1000),
                    // A skipped test did not fail; it is recorded as passed with a note.
                    'passed' => $status === 'passed' || $status === 'skipped',
                    'error_message' => match (true) {
                        $status === 'skipped' => 'Skipped',
                        is_string($error) => $this->message($error, $redactor),
                        $index === 0 && $executionError !== null => $executionError,
                        default => null,
                    },
                ];
            }
        }

        foreach ($rows as $position => &$row) {
            $row = ['position' => $position, ...$row];
        }

        return $rows;
    }

    private function url(mixed $url, Redactor $redactor): ?string
    {
        if (is_string($url)) {
            return Str::limit($redactor->redact($url), 2000);
        }

        if (! is_array($url)) {
            return null;
        }

        $host = implode('.', array_map('strval', (array) ($url['host'] ?? [])));
        $text = ($url['protocol'] ?? 'http').'://'.$host;
        if (! empty($url['port'])) {
            $text .= ':'.$url['port'];
        }
        $path = array_map('strval', (array) ($url['path'] ?? []));
        $text .= $path ? '/'.implode('/', $path) : '';

        $query = [];
        foreach ((array) ($url['query'] ?? []) as $pair) {
            if (is_array($pair) && isset($pair['key']) && empty($pair['disabled'])) {
                $value = $redactor->redactQueryValue((string) $pair['key'], isset($pair['value']) ? (string) $pair['value'] : null);
                $query[] = $pair['key'].($value === null ? '' : '='.$value);
            }
        }

        return Str::limit($redactor->redact($text.($query ? '?'.implode('&', $query) : '')), 2000);
    }

    /**
     * @param  array<mixed>  $executions
     */
    private function firstExecutionError(array $executions, Redactor $redactor): ?string
    {
        foreach ($executions as $execution) {
            foreach ((array) ($execution['errors'] ?? []) as $error) {
                if (is_array($error) && isset($error['message'])) {
                    $name = $execution['requestExecuted']['name'] ?? null;

                    return $this->message(($name ? "{$name}: " : '').$error['message'], $redactor);
                }
            }
        }

        return null;
    }

    private function message(mixed $message, Redactor $redactor): string
    {
        return Str::limit($redactor->redact((string) $message), self::MAX_MESSAGE);
    }

    /**
     * @param  array<mixed>  $summary
     */
    private function int(array $summary, string $group, string $key): ?int
    {
        $value = $summary[$group][$key] ?? null;

        return is_int($value) ? $value : null;
    }
}
