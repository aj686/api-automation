<?php

namespace App\Services;

use App\Models\EnvironmentVariable;

/**
 * Replaces secrets with ******** before anything is stored or shown.
 *
 * This is the phase 9 minimum, required because Postman CLI writes resolved
 * variable values into its log and report (a {{api_key}} in a URL appears in
 * clear — decisions.md D-022): the exact values of the run's secret variables,
 * raw and URL-encoded. Phase 12 adds header and body patterns. Best-effort for
 * secrets the application was never told about.
 */
class Redactor
{
    public const MASK = EnvironmentVariable::MASK;

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
        if ($text === null || $text === '' || $this->values === []) {
            return $text;
        }

        return str_replace($this->values, self::MASK, $text);
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
