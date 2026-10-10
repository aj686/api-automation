<?php

namespace App\Services;

use App\Enums\CollectionKind;
use App\Exceptions\ImportException;
use App\Models\Collection;
use App\Models\EnvironmentVariable;
use App\Models\Project;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Stores Postman collection exports for a project (decisions.md D-021).
 *
 * The file is kept byte-for-byte — it stays a Postman artifact that Postman
 * CLI runs — under storage/app/private/collections/<ulid>.json, a path this
 * class generates, never one taken from the upload.
 */
class CollectionImporter
{
    public const DISK = 'local';

    public const DIRECTORY = 'collections';

    /** Postman CLI's JSON reporter supports v2 collections only (plan section 7). */
    private const SCHEMA_PATTERN = '#/collection/(v2\.[01]\.0)/collection\.json$#';

    /** Auth fields that carry the credential itself, per Postman auth type. */
    private const AUTH_SECRET_FIELDS = ['token', 'password', 'value', 'accessToken', 'clientSecret', 'secretKey', 'refreshToken', 'privateKey'];

    /**
     * @return array{name: string, schema_version: string, requests: int, warnings: list<string>}
     */
    public function inspect(string $json): array
    {
        try {
            $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new ImportException('The file is not valid JSON.');
        }

        if (! is_array($data)) {
            throw new ImportException('This is not a Postman collection.');
        }

        if (isset($data['values']) && ! isset($data['info'])) {
            throw new ImportException('This looks like a Postman environment, not a collection. Import it on an environment page instead.');
        }

        $schema = $data['info']['schema'] ?? null;
        if (! is_string($schema)) {
            throw new ImportException('This is not a Postman v2 collection: it has no info.schema. Export it from Postman as "Collection v2.1".');
        }

        if (! preg_match(self::SCHEMA_PATTERN, $schema, $match)) {
            throw new ImportException('Unsupported collection format. Export it from Postman as "Collection v2.1" — Postman CLI reports only support v2 collections.');
        }

        $name = $data['info']['name'] ?? null;
        if (! is_string($name) || trim($name) === '') {
            throw new ImportException('The collection has no name (info.name).');
        }

        if (! isset($data['item']) || ! is_array($data['item'])) {
            throw new ImportException('The collection has no requests (item list).');
        }

        $scan = ['requests' => 0, 'localhost' => 0, 'literal_credentials' => 0];
        $this->scanItems($data['item'], $scan);
        $this->scanAuth($data['auth'] ?? null, $scan);
        $this->scanVariables($data['variable'] ?? null, $scan);

        return [
            'name' => Str::limit(trim($name), 150, ''),
            'schema_version' => $match[1],
            'requests' => $scan['requests'],
            'warnings' => $this->warnings($scan),
        ];
    }

    /**
     * @param  array{name?: ?string, slug?: ?string, kind?: CollectionKind|string|null}  $attributes
     */
    public function store(Project $project, string $json, string $originalFilename, array $attributes = []): Collection
    {
        $info = $this->inspect($json);
        $path = $this->write($json);

        try {
            return $project->collections()->create([
                'name' => $attributes['name'] ?? $info['name'],
                'slug' => $attributes['slug'] ?? null,
                'kind' => $attributes['kind'] ?? CollectionKind::Other,
                'stored_path' => $path,
                'original_filename' => Str::limit(basename($originalFilename), 255, ''),
                'sha256' => hash('sha256', $json),
                'schema_version' => $info['schema_version'],
            ]);
        } catch (\Throwable $e) {
            Storage::disk(self::DISK)->delete($path);

            throw $e;
        }
    }

    /**
     * Swaps in a new export of the same collection; id, slug and run history stay.
     */
    public function replace(Collection $collection, string $json, string $originalFilename): Collection
    {
        $info = $this->inspect($json);
        $oldPath = $collection->stored_path;
        $newPath = $this->write($json);

        try {
            DB::transaction(fn () => $collection->update([
                'stored_path' => $newPath,
                'original_filename' => Str::limit(basename($originalFilename), 255, ''),
                'sha256' => hash('sha256', $json),
                'schema_version' => $info['schema_version'],
            ]));
        } catch (\Throwable $e) {
            Storage::disk(self::DISK)->delete($newPath);

            throw $e;
        }

        Storage::disk(self::DISK)->delete($oldPath);

        return $collection;
    }

    private function write(string $json): string
    {
        $path = self::DIRECTORY.'/'.Str::lower((string) Str::ulid()).'.json';

        if (! Storage::disk(self::DISK)->put($path, $json)) {
            throw new ImportException('The collection could not be saved to storage.');
        }

        return $path;
    }

    /**
     * @param  array<mixed>  $items
     * @param  array{requests: int, localhost: int, literal_credentials: int}  $scan
     */
    private function scanItems(array $items, array &$scan): void
    {
        foreach ($items as $item) {
            if (! is_array($item)) {
                continue;
            }

            $this->scanAuth($item['auth'] ?? null, $scan);
            $this->scanVariables($item['variable'] ?? null, $scan);

            if (isset($item['item']) && is_array($item['item'])) {
                $this->scanItems($item['item'], $scan);
            }

            if (! isset($item['request'])) {
                continue;
            }

            $scan['requests']++;
            $request = $item['request'];

            $url = is_array($request) ? ($request['url'] ?? '') : $request;
            $raw = is_array($url) ? ($url['raw'] ?? implode('.', (array) ($url['host'] ?? []))) : $url;
            if (is_string($raw) && preg_match('#(^|//|@)(localhost|127\.0\.0\.1)([:/]|$)#i', $raw)) {
                $scan['localhost']++;
            }

            if (is_array($request)) {
                $this->scanAuth($request['auth'] ?? null, $scan);

                foreach ((array) ($request['header'] ?? []) as $header) {
                    if (is_array($header) && strcasecmp((string) ($header['key'] ?? ''), 'Authorization') === 0
                        && $this->isLiteral($header['value'] ?? null)) {
                        $scan['literal_credentials']++;
                    }
                }
            }
        }
    }

    /**
     * Auth blocks look like {"type": "bearer", "bearer": [{"key": "token", "value": "..."}]}.
     *
     * @param  array{requests: int, localhost: int, literal_credentials: int}  $scan
     */
    private function scanAuth(mixed $auth, array &$scan): void
    {
        if (! is_array($auth) || ! isset($auth['type']) || ! is_string($auth['type'])) {
            return;
        }

        foreach ((array) ($auth[$auth['type']] ?? []) as $field) {
            if (is_array($field) && in_array($field['key'] ?? null, self::AUTH_SECRET_FIELDS, true)
                && $this->isLiteral($field['value'] ?? null)) {
                $scan['literal_credentials']++;
            }
        }
    }

    /**
     * @param  array{requests: int, localhost: int, literal_credentials: int}  $scan
     */
    private function scanVariables(mixed $variables, array &$scan): void
    {
        foreach ((array) $variables as $variable) {
            if (is_array($variable) && is_string($variable['key'] ?? null)
                && preg_match(EnvironmentVariable::SECRET_KEY_PATTERN, $variable['key'])
                && $this->isLiteral($variable['value'] ?? null)) {
                $scan['literal_credentials']++;
            }
        }
    }

    /**
     * A real value rather than empty or a {{variable}} reference.
     */
    private function isLiteral(mixed $value): bool
    {
        if (! is_string($value)) {
            return false;
        }

        $value = trim(preg_replace('/^(Bearer|Basic)\s+/i', '', trim($value)));

        return $value !== '' && ! preg_match('/^\{\{[^{}]+\}\}$/', $value);
    }

    /**
     * Counts only — a warning must never repeat a value from the file.
     *
     * @param  array{requests: int, localhost: int, literal_credentials: int}  $scan
     * @return list<string>
     */
    private function warnings(array $scan): array
    {
        $warnings = [];

        if ($scan['requests'] === 0) {
            $warnings[] = 'The collection contains no requests.';
        }

        if ($scan['localhost']) {
            $warnings[] = Str::plural('request', $scan['localhost'], true).' call localhost. Postman CLI runs inside the runner container, where localhost is the container itself — use {{base_url}} with a Docker service name or host.docker.internal.';
        }

        if ($scan['literal_credentials']) {
            $warnings[] = Str::plural('credential', $scan['literal_credentials'], true).' typed directly into the collection (auth settings, collection variables or Authorization headers). Move each into an environment variable marked secret and reference it as {{name}}.';
        }

        return $warnings;
    }
}
