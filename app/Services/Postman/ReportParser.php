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

    /**
     * @param  int  $maxBodyBytes  each request/response body is cut at this size
     *                             (automation.payload_max_bytes, bound in AppServiceProvider)
     */
    public function __construct(private readonly int $maxBodyBytes = 65_536) {}

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
            'requests' => $this->requests($run['executions'], $redactor),
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
        $requestIndex = -1;

        foreach ($executions as $execution) {
            if (! is_array($execution)) {
                continue;
            }
            $requestIndex++;

            $request = is_array($execution['requestExecuted'] ?? null) ? $execution['requestExecuted'] : [];
            $response = is_array($execution['response'] ?? null) ? $execution['response'] : [];
            $errors = array_values(array_filter((array) ($execution['errors'] ?? []), 'is_array'));
            $executionError = $errors ? $this->message($errors[0]['message'] ?? 'Error', $redactor) : null;

            $base = [
                'request_index' => $requestIndex,
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

    /**
     * One entry per executed request, with redacted, size-capped payloads,
     * in the same order (and with the same index) as `request_index` in results().
     *
     * @param  array<mixed>  $executions
     * @return list<array<string, mixed>>
     */
    private function requests(array $executions, Redactor $redactor): array
    {
        $requests = [];

        foreach ($executions as $execution) {
            if (! is_array($execution)) {
                continue;
            }

            $request = is_array($execution['requestExecuted'] ?? null) ? $execution['requestExecuted'] : [];
            $response = is_array($execution['response'] ?? null) ? $execution['response'] : [];
            $errors = array_values(array_filter((array) ($execution['errors'] ?? []), 'is_array'));

            [$requestBody, $requestTruncated] = $this->cap($redactor->redact($this->requestBody($request['body'] ?? null, $redactor)));
            [$responseBody, $responseTruncated, $responseBinary, $responseBytes] = $this->responseBody($response, $redactor);
            $responseHeaders = $this->headers($response['headers'] ?? null, $redactor);

            $requests[] = [
                'position' => count($requests),
                'item_name' => Str::limit($redactor->redact((string) ($request['name'] ?? 'Unnamed request')), 250),
                'method' => isset($request['method']) ? Str::limit((string) $request['method'], 10, '') : null,
                'url' => $this->url($request['url'] ?? null, $redactor),
                'request_headers' => $this->headers($request['headers'] ?? null, $redactor),
                'request_body' => $requestBody,
                'request_body_truncated' => $requestTruncated,
                'response_code' => isset($response['code']) && is_int($response['code']) ? $response['code'] : null,
                'response_status' => isset($response['status']) ? Str::limit((string) $response['status'], 100, '') : null,
                'response_headers' => $responseHeaders,
                'response_body' => $responseBody,
                'response_body_truncated' => $responseTruncated,
                'response_body_binary' => $responseBinary,
                'response_content_type' => $this->headerValue($responseHeaders, 'content-type'),
                'response_size' => $responseBytes ?? (isset($response['responseSize']) && is_numeric($response['responseSize']) ? (int) $response['responseSize'] : null),
                'response_time_ms' => isset($response['responseTime']) && is_numeric($response['responseTime']) ? (int) $response['responseTime'] : null,
                'error_message' => $errors ? $this->message($errors[0]['message'] ?? 'Error', $redactor) : null,
            ];
        }

        return $requests;
    }

    /**
     * Postman's body object as text: raw as is, urlencoded as a query string,
     * form-data as "key: value" lines, GraphQL as JSON.
     */
    private function requestBody(mixed $body, Redactor $redactor): ?string
    {
        if (! is_array($body) || ! isset($body['mode'])) {
            return null;
        }

        $pairs = fn (string $mode) => array_values(array_filter((array) ($body[$mode] ?? []), fn ($p) => is_array($p) && isset($p['key']) && empty($p['disabled'])));

        return match ($body['mode']) {
            'raw' => isset($body['raw']) && $body['raw'] !== '' ? (string) $body['raw'] : null,
            'urlencoded' => implode('&', array_map(
                fn ($p) => $p['key'].'='.$redactor->redactQueryValue((string) $p['key'], isset($p['value']) ? (string) $p['value'] : ''),
                $pairs('urlencoded'),
            )) ?: null,
            'formdata' => implode("\n", array_map(
                fn ($p) => $p['key'].': '.(($p['type'] ?? 'text') === 'file' ? '[file]' : $redactor->redactQueryValue((string) $p['key'], isset($p['value']) ? (string) $p['value'] : '')),
                $pairs('formdata'),
            )) ?: null,
            'graphql' => json_encode($body['graphql'] ?? null, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            'file' => '[file]',
            default => null,
        };
    }

    /**
     * The body as compacted by scripts/compact-postman-report.mjs, or decoded
     * from the CLI's raw {type: Buffer, data: [...]} when it was not compacted.
     *
     * @return array{?string, bool, bool, ?int} body, truncated, binary, size in bytes
     */
    private function responseBody(array $response, Redactor $redactor): array
    {
        if (array_key_exists('body', $response) && array_key_exists('bodyBytes', $response)) {
            if (! empty($response['bodyBinary'])) {
                return [null, false, true, (int) $response['bodyBytes']];
            }
            [$body, $cut] = $this->cap($redactor->redact($response['body'] === null ? null : (string) $response['body']));

            return [$body, $cut || ! empty($response['bodyTruncated']), false, (int) $response['bodyBytes']];
        }

        $stream = $response['stream'] ?? null;
        if (! is_array($stream) || ($stream['type'] ?? null) !== 'Buffer' || ! is_array($stream['data'] ?? null)) {
            return [null, false, false, null];
        }

        $bytes = count($stream['data']);
        $raw = pack('C*', ...array_slice($stream['data'], 0, $this->maxBodyBytes + 4));
        if (! mb_check_encoding($raw, 'UTF-8') && ! mb_check_encoding(mb_strcut($raw, 0, $this->maxBodyBytes), 'UTF-8')) {
            return [null, false, true, $bytes];
        }
        [$body, $cut] = $this->cap($redactor->redact($raw));

        return [$body === '' ? null : $body, $cut || $bytes > $this->maxBodyBytes, false, $bytes];
    }

    /**
     * @return array{?string, bool}
     */
    private function cap(?string $text): array
    {
        $max = $this->maxBodyBytes;

        if ($text === null || strlen($text) <= $max) {
            return [$text, false];
        }

        return [mb_strcut($text, 0, $max), true];
    }

    /**
     * Postman header lists ([{key, value}]) as [{name, value}], redacted, without
     * the Postman-Token header Postman adds to every request.
     *
     * @return list<array{name: string, value: ?string}>|null
     */
    private function headers(mixed $headers, Redactor $redactor): ?array
    {
        if (! is_array($headers)) {
            return null;
        }

        $out = [];
        foreach ($headers as $header) {
            if (! is_array($header) || ! isset($header['key']) || strcasecmp((string) $header['key'], 'Postman-Token') === 0) {
                continue;
            }
            $name = (string) $header['key'];
            $out[] = ['name' => $name, 'value' => $redactor->redactHeaderValue($name, isset($header['value']) ? (string) $header['value'] : null)];
        }

        return $out;
    }

    /**
     * @param  list<array{name: string, value: ?string}>|null  $headers
     */
    private function headerValue(?array $headers, string $name): ?string
    {
        foreach ($headers ?? [] as $header) {
            if (strcasecmp($header['name'], $name) === 0) {
                return Str::limit((string) $header['value'], 250, '');
            }
        }

        return null;
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
