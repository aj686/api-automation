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
     * @param  list<array<string, mixed>>  $results  one row per assertion; `request_index` points into $requests
     * @param  list<array<string, mixed>>  $requests  one entry per executed request, with payloads (D-037)
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
        public readonly array $requests = [],
    ) {}
}
