<?php

namespace Tests\Unit\Postman;

use App\Enums\RunStatus;
use App\Services\Postman\ParsedReport;
use App\Services\Postman\ReportParser;
use App\Services\Redactor;
use PHPUnit\Framework\TestCase;

/**
 * Against reports captured from Postman CLI 1.71.0 by scripts/verify-postman-cli.sh.
 */
class ReportParserTest extends TestCase
{
    private function fixture(string $case): string
    {
        return file_get_contents(__DIR__.'/../../fixtures/postman/'.$case.'.report.json');
    }

    private function parse(string $case, int $exit, array $secrets = ['YOUR_API_KEY']): ParsedReport
    {
        return (new ReportParser)->parse($this->fixture($case), $exit, new Redactor($secrets));
    }

    public function test_pass(): void
    {
        $report = $this->parse('pass', 0);

        $this->assertSame(RunStatus::Pass, $report->status);
        $this->assertSame([1, 2, 2, 0], [$report->totalRequests, $report->totalAssertions, $report->passedAssertions, $report->failedAssertions]);
        $this->assertCount(2, $report->results);
        $this->assertSame([
            'position' => 0, 'item_name' => 'Health', 'method' => 'GET',
            'url' => 'http://nginx/up?api_key=********', 'response_code' => 200,
            'response_time_ms' => $report->results[0]['response_time_ms'],
            'assertion' => 'status is 200', 'passed' => true, 'error_message' => null,
        ], $report->results[0]);
    }

    public function test_the_secret_in_the_url_is_redacted_everywhere(): void
    {
        foreach (['pass' => 0, 'fail' => 1, 'scripterror' => 1] as $case => $exit) {
            $this->assertStringContainsString('YOUR_API_KEY', $this->fixture($case), "fixture {$case} carries the secret");
            $this->assertStringNotContainsString('YOUR_API_KEY', json_encode($this->parse($case, $exit)), $case);
        }
    }

    public function test_a_credential_named_query_value_is_masked_even_when_unknown(): void
    {
        $report = $this->parse('pass', 0, secrets: []);

        $this->assertSame('http://nginx/up?api_key=********', $report->results[0]['url']);
    }

    public function test_failed_assertion_is_fail_with_the_message(): void
    {
        $report = $this->parse('fail', 1);

        $this->assertSame(RunStatus::Fail, $report->status);
        $this->assertNull($report->errorCode);
        $this->assertSame([2, 3, 2, 1], [$report->totalRequests, $report->totalAssertions, $report->passedAssertions, $report->failedAssertions]);

        $failed = array_values(array_filter($report->results, fn ($r) => ! $r['passed']));
        $this->assertCount(1, $failed);
        $this->assertSame('expects 201', $failed[0]['assertion']);
        $this->assertSame('expected response to have status code 201 but got 200', $failed[0]['error_message']);
    }

    public function test_an_expected_404_passes(): void
    {
        $row = collect($this->parse('fail', 1)->results)->firstWhere('item_name', 'Missing');

        $this->assertSame(404, $row['response_code']);
        $this->assertTrue($row['passed']);
    }

    public function test_unreachable_host_is_error_not_fail(): void
    {
        $report = $this->parse('unreachable', 1);

        $this->assertSame(RunStatus::Error, $report->status);
        $this->assertSame('request_error', $report->errorCode);
        $this->assertSame('Nowhere: getaddrinfo ENOTFOUND does-not-exist.invalid', $report->errorMessage);
        $this->assertNull($report->results[0]['response_code']);
    }

    public function test_request_timeout_is_error(): void
    {
        $report = $this->parse('requesttimeout', 1);

        $this->assertSame(RunStatus::Error, $report->status);
        $this->assertSame('request_error', $report->errorCode);
        $this->assertStringContainsString('ETIMEDOUT', $report->errorMessage);
    }

    public function test_script_error_is_error(): void
    {
        $report = $this->parse('scripterror', 1);

        $this->assertSame(RunStatus::Error, $report->status);
        $this->assertSame('script_error', $report->errorCode);
        $this->assertSame('Health: undefinedFunction is not defined', $report->errorMessage);
        $this->assertSame(0, $report->totalAssertions);
        $this->assertFalse($report->results[0]['passed']);
    }

    public function test_no_tests_passes_with_zero_assertions(): void
    {
        $report = $this->parse('notests', 0);

        $this->assertSame(RunStatus::Pass, $report->status);
        $this->assertSame(0, $report->totalAssertions);
        $this->assertCount(1, $report->results);
        $this->assertNull($report->results[0]['assertion']);
    }

    public function test_exit_code_alone_never_passes_or_fails(): void
    {
        // Clean report, non-zero exit: something went wrong that the report does not show.
        $this->assertSame('unexpected_exit', $this->parse('pass', 1)->errorCode);
        // Failing report, zero exit (e.g. -x was used): still a FAIL.
        $this->assertSame(RunStatus::Fail, $this->parse('fail', 0)->status);
    }

    public function test_missing_or_unreadable_reports_are_errors_with_unknown_counts(): void
    {
        $parser = new ReportParser;

        foreach ([null => 'report_missing', '' => 'report_missing', '{not json' => 'report_invalid', '{"run":{}}' => 'report_invalid'] as $json => $code) {
            $report = $parser->parse($json === '' ? null : (string) $json, 1, new Redactor);
            $this->assertSame(RunStatus::Error, $report->status);
            $this->assertSame($code, $report->errorCode);
            $this->assertNull($report->totalAssertions, 'unknown, not zero');
        }
    }
}
