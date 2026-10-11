<?php

namespace App\Services;

use App\Models\EnvironmentVariable;

/**
 * Replaces secrets with ******** before anything is stored or shown.
 *
 * Two passes (plan section 6, decisions.md D-025, D-029):
 *  1. the exact values of the run's secret variables, raw and URL-encoded —
 *     required because Postman CLI writes resolved values into its log and
 *     report (D-022);
 *  2. patterns for secrets the application was never told about:
 *     Authorization / Proxy-Authorization headers, bare Bearer tokens,
 *     Cookie / Set-Cookie headers, and password=, api_key=, apikey=,
 *     client_secret=, access_token=, token= (and similar) as query/form
 *     pairs or JSON members.
 *
 * Pass 2 is best-effort: a secret in a shape it does not know is not caught.
 */
class Redactor
{
    public const MASK = EnvironmentVariable::MASK;

    /** Credential names for key=value and JSON patterns; narrower than SECRET_KEY_PATTERN so "author=" is left alone. */
    private const KEY_NAMES = 'password|passwd|pwd|client[_-]?secret|secret|api[_-]?key|apikey|access[_-]?token|refresh[_-]?token|id[_-]?token|auth[_-]?token|session[_-]?id|token';

    /** Headers whose value is a credential, whatever it looks like (D-032, D-037). */
    public const CREDENTIAL_HEADERS = [
        'authorization', 'proxy-authorization', 'cookie', 'set-cookie',
        'x-api-key', 'api-key', 'apikey', 'x-auth-token', 'x-access-token',
        'x-csrf-token', 'x-xsrf-token', 'x-amz-security-token',
    ];

    /** @var list<string> longest first, so a value containing another is masked whole */
    private array $values;

    /**
     * @param  iterable<string|null>  $secretValues
     */
    public function __construct(iterable $secretValues = [])
    {
        $values = [];
        foreach ($secretValues as $value) {
            // Very short values would mask ordinary text everywhere ("1", "ok").
            if (is_string($value) && mb_strlen($value) >= 4) {
                $values[] = $value;
                $values[] = rawurlencode($value);
                $values[] = urlencode($value);
            }
        }

        $values = array_values(array_unique($values));
        usort($values, fn (string $a, string $b) => strlen($b) <=> strlen($a));
        $this->values = $values;
    }

    public function redact(?string $text): ?string
    {
        if ($text === null || $text === '') {
            return $text;
        }

        if ($this->values !== []) {
            $text = str_replace($this->values, self::MASK, $text);
        }

        return $this->redactPatterns($text);
    }

    private function redactPatterns(string $text): string
    {
        $mask = self::MASK;
        $keys = self::KEY_NAMES;

        $patterns = [
            // Authorization: Bearer abc / "authorization": "Basic abc" — keep the scheme, mask the credential.
            '/(\b(?:proxy-)?authorization\b["\']?\s*[:=]\s*["\']?)((?:bearer|basic|digest|token)\s+)?[^\s"\',;&]+/i' => '$1$2'.$mask,
            // A bearer token on its own (8+ characters, so "Bearer token missing" stays readable).
            '/\b(bearer\s+)(?!\*{8})[A-Za-z0-9\-._~+\/]{8,}=*/i' => '$1'.$mask,
            // Cookie: a=b; c=d — the whole header value.
            '/(\b(?:set-)?cookie\b["\']?\s*:\s*["\']?)[^\r\n"\']+/i' => '$1'.$mask,
            // "password": "x", "access_token":"x"
            '/("(?:'.$keys.')"\s*:\s*)"(?:[^"\\\\]|\\\\.)*"/i' => '$1"'.$mask.'"',
            // password=x, ?api_key=x&, token=x
            '/(?<![A-Za-z0-9])((?:'.$keys.')=)(?!\*{8})[^&\s"\'#<>]+/i' => '$1'.$mask,
        ];

        return preg_replace(array_keys($patterns), array_values($patterns), $text) ?? $text;
    }

    public static function isCredentialHeader(string $name): bool
    {
        $name = strtolower(trim($name));

        return in_array($name, self::CREDENTIAL_HEADERS, true)
            || preg_match(EnvironmentVariable::SECRET_KEY_PATTERN, $name) === 1;
    }

    /**
     * A header value as it may be shown: credential headers keep only their
     * scheme ("Bearer ********"), everything else goes through redact().
     */
    public function redactHeaderValue(string $name, ?string $value): ?string
    {
        if ($value === null || $value === '') {
            return $value;
        }

        if (self::isCredentialHeader($name)) {
            return preg_match('/^(bearer|basic|digest|token)\s+/i', $value, $m) ? $m[1].' '.self::MASK : self::MASK;
        }

        return $this->redact($value);
    }

    /**
     * Mask a query-string value when its name looks like a credential,
     * whether or not the value is a known secret.
     */
    public function redactQueryValue(string $key, ?string $value): ?string
    {
        if ($value !== null && $value !== '' && preg_match(EnvironmentVariable::SECRET_KEY_PATTERN, $key)) {
            return self::MASK;
        }

        return $this->redact($value);
    }
}
