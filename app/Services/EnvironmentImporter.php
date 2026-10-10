<?php

namespace App\Services;

use App\Exceptions\ImportException;
use App\Models\Environment;
use App\Models\EnvironmentVariable;
use Illuminate\Support\Facades\DB;

/**
 * Imports variables from a Postman environment export into an environment.
 *
 * Accepted shape (decisions.md D-017):
 *   {"name": "...", "values": [{"key": "...", "value": "...", ...}, ...]}
 * A variable is secret when `type` is "secret" (Postman app exports) or
 * `secret` is true (postman-collection's Variable), and disabled when
 * `enabled` is false or `disabled` is true. Keys that look like credentials
 * are marked secret even when the file says otherwise.
 */
class EnvironmentImporter
{
    /**
     * @return array{added: int, updated: int, kept: int, secret_by_name: int}
     */
    public function import(Environment $environment, string $json): array
    {
        $variables = $this->parse($json);
        $counts = ['added' => 0, 'updated' => 0, 'kept' => 0, 'secret_by_name' => 0];

        DB::transaction(function () use ($environment, $variables, &$counts) {
            foreach ($variables as $variable) {
                $existing = $environment->variables()->where('key', $variable['key'])->first();
                // Only count rows the name rule actually made secret, so the message is true.
                $counts['secret_by_name'] += (int) ($variable['secret_by_name'] && ! $existing?->is_secret);

                if (! $existing) {
                    $environment->variables()->create($this->attributes($variable));
                    $counts['added']++;

                    continue;
                }

                // An empty value in the file never wipes a stored one: Postman
                // exports can leave values out (e.g. secrets kept in the vault).
                if ($variable['value'] === null && $existing->value !== null) {
                    $existing->update(['is_secret' => $existing->is_secret || $variable['is_secret']]);
                    $counts['kept']++;

                    continue;
                }

                // Never downgrade a secret to plain text through an import.
                $existing->update([
                    ...$this->attributes($variable),
                    'is_secret' => $existing->is_secret || $variable['is_secret'],
                ]);
                $counts['updated']++;
            }
        });

        return $counts;
    }

    /**
     * @return list<array{key: string, value: ?string, is_secret: bool, enabled: bool, secret_by_name: bool}>
     */
    public function parse(string $json): array
    {
        try {
            $data = json_decode($json, true, 32, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new ImportException('The file is not valid JSON.');
        }

        if (! is_array($data) || ! isset($data['values']) || ! is_array($data['values'])) {
            throw new ImportException('This is not a Postman environment export: it has no "values" list. Export it from Postman with Environments → ⋯ → Export.');
        }

        if (isset($data['info']['schema']) || isset($data['item'])) {
            throw new ImportException('This looks like a Postman collection, not an environment.');
        }

        $variables = [];
        $seen = [];

        foreach (array_values($data['values']) as $index => $row) {
            $position = $index + 1;

            if (! is_array($row) || ! isset($row['key']) || ! is_string($row['key'])) {
                throw new ImportException("Variable #{$position} has no key.");
            }

            $key = trim($row['key']);

            if (! preg_match(EnvironmentVariable::KEY_PATTERN, $key)) {
                throw new ImportException("Variable #{$position} has an unsupported key. Keys may use letters, numbers, dot, underscore and hyphen, up to 100 characters.");
            }

            if (isset($seen[$key])) {
                throw new ImportException("The key {$key} appears more than once.");
            }
            $seen[$key] = true;

            $value = $row['value'] ?? null;
            if (is_bool($value) || is_int($value) || is_float($value)) {
                $value = var_export($value, true);
            }
            if ($value !== null && ! is_string($value)) {
                throw new ImportException("The value of {$key} is not text.");
            }
            if ($value !== null && mb_strlen($value) > EnvironmentVariable::MAX_VALUE_LENGTH) {
                throw new ImportException("The value of {$key} is longer than ".EnvironmentVariable::MAX_VALUE_LENGTH.' characters.');
            }

            $declaredSecret = ($row['type'] ?? null) === 'secret' || ($row['secret'] ?? false) === true;
            $secretByName = ! $declaredSecret && preg_match(EnvironmentVariable::SECRET_KEY_PATTERN, $key) === 1;

            $variables[] = [
                'key' => $key,
                'value' => $value === '' ? null : $value,
                'is_secret' => $declaredSecret || $secretByName,
                'enabled' => ($row['enabled'] ?? true) !== false && ($row['disabled'] ?? false) !== true,
                'secret_by_name' => $secretByName,
            ];
        }

        return $variables;
    }

    /**
     * @param  array{key: string, value: ?string, is_secret: bool, enabled: bool, secret_by_name: bool}  $variable
     * @return array{key: string, value: ?string, is_secret: bool, enabled: bool}
     */
    private function attributes(array $variable): array
    {
        return [
            'key' => $variable['key'],
            'value' => $variable['value'],
            'is_secret' => $variable['is_secret'],
            'enabled' => $variable['enabled'],
        ];
    }
}
