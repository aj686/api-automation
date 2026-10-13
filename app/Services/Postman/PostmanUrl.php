<?php

namespace App\Services\Postman;

/**
 * Turns a raw URL as typed in the editor into the object Postman stores.
 *
 * Postman CLI sends to the parts (protocol, host, port, path, query) and
 * ignores `raw` — an object with only `raw` goes to "/" (decisions.md D-038) —
 * so the parts must be filled the way Postman's own app fills them.
 * {{variables}} stay as they are; nothing is encoded or decoded.
 */
class PostmanUrl
{
    /**
     * @param  list<array{key: string, value: string, enabled: bool, description?: string}>  $params  query rows, also disabled ones
     * @param  mixed  $previous  the stored url, for path variables (url.variable)
     */
    public static function toObject(string $raw, array $params, mixed $previous = null): \stdClass
    {
        $raw = trim($raw);
        $url = new \stdClass;
        $url->raw = $raw;

        $rest = $raw;
        $hash = null;
        if (($at = strpos($rest, '#')) !== false) {
            $hash = substr($rest, $at + 1);
            $rest = substr($rest, 0, $at);
        }
        if (($at = strpos($rest, '?')) !== false) {
            $rest = substr($rest, 0, $at);
        }

        if (preg_match('#^([A-Za-z][A-Za-z0-9+.-]*)://#', $rest, $match)) {
            $url->protocol = $match[1];
            $rest = substr($rest, strlen($match[0]));
        }

        $slash = self::outsideVariables($rest, '/');
        $hostPart = $slash === null ? $rest : substr($rest, 0, $slash);
        $pathPart = $slash === null ? null : substr($rest, $slash + 1);

        if (preg_match('/^(.*):(\d+|\{\{[^{}]+\}\})$/', $hostPart, $match) && $match[1] !== '') {
            $hostPart = $match[1];
            $url->port = $match[2];
        }

        if ($hostPart !== '') {
            $url->host = self::splitOutsideVariables($hostPart, '.');
        }
        if ($pathPart !== null) {
            $url->path = explode('/', $pathPart);
        }

        $query = [];
        foreach ($params as $param) {
            if (trim($param['key']) === '' && trim($param['value']) === '') {
                continue;
            }
            $row = new \stdClass;
            $row->key = $param['key'];
            $row->value = $param['value'];
            if (! $param['enabled']) {
                $row->disabled = true;
            }
            if (($param['description'] ?? '') !== '') {
                $row->description = $param['description'];
            }
            $query[] = $row;
        }
        if ($query) {
            $url->query = $query;
        }

        if ($hash !== null && $hash !== '') {
            $url->hash = $hash;
        }

        // Path variables (:id) live in url.variable; keep the stored values.
        if ($previous instanceof \stdClass && isset($previous->variable) && is_array($previous->variable)) {
            $url->variable = $previous->variable;
        }

        return $url;
    }

    /**
     * The query rows written in a raw URL, all enabled.
     *
     * @return list<array{key: string, value: string, enabled: bool}>
     */
    public static function queryFromRaw(string $raw): array
    {
        $raw = explode('#', $raw, 2)[0];
        if (($at = strpos($raw, '?')) === false) {
            return [];
        }

        $rows = [];
        foreach (explode('&', substr($raw, $at + 1)) as $pair) {
            if ($pair === '') {
                continue;
            }
            [$key, $value] = array_pad(explode('=', $pair, 2), 2, '');
            $rows[] = ['key' => $key, 'value' => $value, 'enabled' => true];
        }

        return $rows;
    }

    /**
     * The raw URL with its query replaced by the enabled rows.
     *
     * @param  list<array{key: string, value: string, enabled: bool}>  $params
     */
    public static function withQuery(string $raw, array $params): string
    {
        [$withoutHash, $hash] = array_pad(explode('#', $raw, 2), 2, null);
        $base = explode('?', $withoutHash, 2)[0];

        $pairs = [];
        foreach ($params as $param) {
            if ($param['enabled'] && trim($param['key']) !== '') {
                $pairs[] = $param['key'].($param['value'] !== '' ? '='.$param['value'] : '');
            }
        }

        return $base.($pairs ? '?'.implode('&', $pairs) : '').($hash !== null ? '#'.$hash : '');
    }

    /**
     * A stored url (string or object) as the raw text the editor shows.
     */
    public static function raw(mixed $url): string
    {
        if (is_string($url)) {
            return $url;
        }
        if (! $url instanceof \stdClass) {
            return '';
        }
        if (isset($url->raw) && is_string($url->raw)) {
            return $url->raw;
        }

        $raw = isset($url->protocol) ? $url->protocol.'://' : '';
        $raw .= implode('.', (array) ($url->host ?? []));
        $raw .= isset($url->port) ? ':'.$url->port : '';
        $raw .= isset($url->path) ? '/'.implode('/', (array) $url->path) : '';

        return $raw;
    }

    /**
     * Query rows of a stored url, including disabled ones.
     *
     * @return list<array{key: string, value: string, enabled: bool, description: string}>
     */
    public static function params(mixed $url): array
    {
        if (is_string($url)) {
            return array_map(fn ($row) => $row + ['description' => ''], self::queryFromRaw($url));
        }
        if (! $url instanceof \stdClass) {
            return [];
        }

        $rows = [];
        foreach ((array) ($url->query ?? []) as $row) {
            if ($row instanceof \stdClass) {
                $rows[] = [
                    'key' => (string) ($row->key ?? ''),
                    'value' => (string) ($row->value ?? ''),
                    'enabled' => empty($row->disabled),
                    'description' => is_string($row->description ?? null) ? $row->description : '',
                ];
            }
        }

        return $rows;
    }

    private static function outsideVariables(string $text, string $char): ?int
    {
        $depth = 0;
        for ($i = 0, $n = strlen($text); $i < $n; $i++) {
            if (substr($text, $i, 2) === '{{') {
                $depth++;
                $i++;
            } elseif (substr($text, $i, 2) === '}}' && $depth > 0) {
                $depth--;
                $i++;
            } elseif ($depth === 0 && $text[$i] === $char) {
                return $i;
            }
        }

        return null;
    }

    /**
     * @return list<string>
     */
    private static function splitOutsideVariables(string $text, string $char): array
    {
        $parts = [];
        while (($at = self::outsideVariables($text, $char)) !== null) {
            $parts[] = substr($text, 0, $at);
            $text = substr($text, $at + 1);
        }
        $parts[] = $text;

        return $parts;
    }
}
