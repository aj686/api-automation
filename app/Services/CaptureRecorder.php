<?php

namespace App\Services;

use App\Models\CaptureBucket;
use App\Models\CapturedRequest;
use App\Models\EnvironmentVariable;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Stores one call received on a capture URL, redacted (plan section 13,
 * loophole 39, decisions.md D-032).
 */
class CaptureRecorder
{
    /** Bodies above this are cut (loophole 38). */
    public const MAX_BODY_BYTES = 262_144;

    /** Headers whose value is a credential: only their presence is kept. */
    private const CREDENTIAL_HEADERS = [
        'authorization', 'proxy-authorization', 'cookie', 'set-cookie',
        'x-api-key', 'api-key', 'apikey', 'x-auth-token', 'x-access-token',
        'x-csrf-token', 'x-xsrf-token', 'x-amz-security-token',
    ];

    public const PRESENT = '[present]';

    public function record(CaptureBucket $bucket, Request $request, string $subPath): CapturedRequest
    {
        $redactor = new Redactor($bucket->environment->variables()->where('is_secret', true)->pluck('value'));

        $headers = [];
        foreach ($request->headers->all() as $name => $values) {
            $headers[$name] = $this->isCredentialHeader($name)
                ? self::PRESENT
                : $redactor->redact(implode(', ', $values));
        }
        ksort($headers);

        [$body, $truncated] = $this->body($request->getContent(), $redactor);

        $captured = $bucket->requests()->create([
            'method' => Str::limit($request->method(), 10, ''),
            'path' => Str::limit('/'.ltrim($subPath, '/'), 2000, ''),
            'query' => $redactor->redact($request->getQueryString()),
            'headers' => $headers,
            'body' => $body,
            'body_truncated' => $truncated,
            'received_at' => now(),
        ]);

        $bucket->pruneExpired();

        return $captured;
    }

    private function isCredentialHeader(string $name): bool
    {
        $name = strtolower($name);

        return in_array($name, self::CREDENTIAL_HEADERS, true)
            || preg_match(EnvironmentVariable::SECRET_KEY_PATTERN, $name) === 1;
    }

    /**
     * @return array{?string, bool}
     */
    private function body(string $raw, Redactor $redactor): array
    {
        if ($raw === '') {
            return [null, false];
        }

        if (! mb_check_encoding($raw, 'UTF-8')) {
            return ['[binary body, '.strlen($raw).' bytes, not stored]', false];
        }

        $body = $redactor->redact($raw);

        return strlen($body) > self::MAX_BODY_BYTES
            ? [mb_strcut($body, 0, self::MAX_BODY_BYTES), true]
            : [$body, false];
    }
}
