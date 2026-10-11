<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\WithoutTimestamps;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One request a run executed, with request and response payloads as Postman
 * shows them (decisions.md D-037). Already redacted when stored; headers and
 * bodies are removed after the retention period (payload_purged_at).
 */
#[Fillable([
    'position', 'item_name', 'method', 'url',
    'request_headers', 'request_body', 'request_body_truncated',
    'response_code', 'response_status', 'response_headers', 'response_body',
    'response_body_truncated', 'response_body_binary', 'response_content_type',
    'response_size', 'response_time_ms', 'error_message', 'payload_purged_at',
])]
#[WithoutTimestamps]
class RunRequest extends Model
{
    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'request_headers' => 'array',
            'response_headers' => 'array',
            'request_body_truncated' => 'boolean',
            'response_body_truncated' => 'boolean',
            'response_body_binary' => 'boolean',
            'payload_purged_at' => 'datetime',
        ];
    }

    /**
     * Passed when every assertion passed and the request itself did not error.
     */
    public function passed(): bool
    {
        return $this->error_message === null && $this->results->every->passed;
    }

    /**
     * The response body as pretty JSON when it is JSON, else as stored.
     */
    public function prettyResponseBody(): ?string
    {
        return self::pretty($this->response_body);
    }

    public function prettyRequestBody(): ?string
    {
        return self::pretty($this->request_body);
    }

    private static function pretty(?string $body): ?string
    {
        if ($body === null || $body === '') {
            return $body;
        }

        $decoded = json_decode($body, true);

        return json_last_error() === JSON_ERROR_NONE && is_array($decoded)
            ? json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
            : $body;
    }

    /**
     * @return BelongsTo<Run, $this>
     */
    public function run(): BelongsTo
    {
        return $this->belongsTo(Run::class);
    }

    /**
     * @return HasMany<RunResult, $this>
     */
    public function results(): HasMany
    {
        return $this->hasMany(RunResult::class)->orderBy('position');
    }
}
