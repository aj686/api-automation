<?php

namespace App\Services\Postman;

use App\Enums\RunStatus;

/**
 * What a Postman CLI run produced, already redacted. Counts are null when the
 * report could not tell (plan section 4: NULL means unknown, never zero).
 */
final class ParsedReport
{
    /**
     * @param  list<array{position: int, item_name: string, method: ?string, url: ?string, response_code: ?int, response_time_ms: ?int, assertion: ?string, passed: bool, error_message: ?string}>  $results
     */
    public function __construct(
        public readonly RunStatus $status,
        public readonly ?string $errorCode = null,
        public readonly ?string $errorMessage = null,
        public readonly ?int $totalRequests = null,
        public readonly ?int $totalAssertions = null,
        public readonly ?int $passedAssertions = null,
        public readonly ?int $failedAssertions = null,
        public readonly array $results = [],
    ) {}
}
