<?php

namespace App\Services\Postman;

/**
 * "Keep variable values" (decisions.md D-039): what scripts set with
 * pm.environment.set() comes back from Postman CLI 1.71.0, which has no
 * --export-environment and whose default JSON report holds no variables.
 *
 * For the run only, a copy of the collection gets one extra test script per
 * request (resources/postman/capture-variables.js), after the request's own
 * scripts — a second `test` event runs even when the first one throws. It
 * prints the environment as hex between two markers: the CLI wraps long
 * console lines, and hex survives that by dropping every other character.
 * The block is removed from the output before anything is redacted or stored.
 */
class VariableCapture
{
    public const BEGIN = 'AAVARSBEGIN';

    public const END = 'AAVARSEND';

    public static function script(): string
    {
        return trim((string) file_get_contents(resource_path('postman/capture-variables.js')));
    }

    /**
     * The collection JSON with the capture script after every request's scripts.
     */
    public function inject(string $json): string
    {
        $document = CollectionDocument::fromJson($json);
        $document->appendTestScriptToRequests(self::script());

        return $document->toJson();
    }

    /**
     * The environment after the last request that printed one, or null.
     *
     * @return array<string, string>|null
     */
    public function extract(string $output): ?array
    {
        if (! preg_match_all('/'.self::BEGIN.'(.*?)'.self::END.'/s', $output, $matches)) {
            return null;
        }

        $hex = preg_replace('/[^0-9a-f]/', '', (string) end($matches[1]));
        if ($hex === '' || strlen($hex) % 2 !== 0) {
            return null;
        }

        $values = json_decode(rawurldecode((string) hex2bin($hex)), true);
        if (! is_array($values)) {
            return null;
        }

        $out = [];
        foreach ($values as $key => $value) {
            $out[(string) $key] = is_scalar($value) || $value === null ? (string) $value : (string) json_encode($value);
        }

        return $out;
    }

    /**
     * The output without the capture blocks: every line from the one holding
     * BEGIN to the one holding END. They carry every value in clear text.
     */
    public function strip(string $output): string
    {
        $lines = preg_split('/\R/', $output);
        $kept = [];
        $inside = false;
        foreach ($lines as $line) {
            if (! $inside && str_contains($line, self::BEGIN)) {
                $inside = true;
            }
            if (! $inside) {
                $kept[] = $line;
            }
            if ($inside && str_contains($line, self::END)) {
                $inside = false;
            }
        }

        return implode("\n", $kept);
    }
}
