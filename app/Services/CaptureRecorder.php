<?php

namespace App\Services;

use App\Models\CaptureBucket;
use App\Models\CapturedRequest;
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

    // Credential headers (Redactor::CREDENTIAL_HEADERS) are stored as present only.

    public const PRESENT = '[present]';

    public function record(CaptureBucket $bucket, Request $request, string $subPath): CapturedRequest
    {
        $redactor = new Redactor($bucket->environment->variables()->where('is_secret', true)->pluck('value'));

        $headers = [];
        foreach ($request->headers->all() as $name => $values) {
            $headers[$name] = Redactor::isCredentialHeader($name)
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
