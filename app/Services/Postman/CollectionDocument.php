<?php

namespace App\Services\Postman;

use App\Exceptions\ImportException;
use Illuminate\Support\Str;

/**
 * A Postman v2 collection as an editable tree (decisions.md D-038).
 *
 * Decoded to stdClass, not arrays: PHP arrays turn `{}` into `[]`, which would
 * change Postman's own fields on every save. Unknown fields are kept as they
 * are; only what the editor shows is rewritten.
 *
 * Every item gets an `id` (v2.1 allows one) because Postman CLI's `-i` finds
 * items by id; the "Folder/Request" path form from --help is not found in
 * 1.71.0, and a name picks the first match.
 */
class CollectionDocument
{
    /** Auth types the editor writes; others are kept untouched. */
    public const AUTH_TYPES = ['inherit', 'noauth', 'bearer', 'basic', 'apikey'];

    public const BODY_MODES = ['none', 'raw', 'urlencoded', 'formdata'];

    public const RAW_LANGUAGES = ['json', 'text', 'javascript', 'html', 'xml'];

    public const METHODS = ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'HEAD', 'OPTIONS'];

    /**
     * Raw bodies above this are shown read-only: the form travels with every
     * Livewire request, and Livewire refuses payloads over 1 MB.
     */
    public const RAW_EDIT_MAX_BYTES = 262_144;

    private function __construct(private \stdClass $data) {}

    /**
     * Parse only; validation is CollectionImporter::inspect().
     */
    public static function fromJson(string $json): self
    {
        try {
            $data = json_decode($json, false, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new ImportException('The file is not valid JSON.');
        }

        if (! $data instanceof \stdClass || ! isset($data->item) || ! is_array($data->item)) {
            throw new ImportException('This is not a Postman collection.');
        }

        return new self($data);
    }

    public function toJson(): string
    {
        return json_encode($this->data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)."\n";
    }

    public function name(): string
    {
        return (string) ($this->data->info->name ?? '');
    }

    /**
     * Gives every item a unique id. True when something changed.
     */
    public function ensureIds(): bool
    {
        $seen = [];
        $changed = false;

        $this->walk($this->data->item, function (\stdClass $item) use (&$seen, &$changed) {
            $id = $item->id ?? null;
            if (! is_string($id) || $id === '' || isset($seen[$id])) {
                $item->id = (string) Str::uuid();
                $changed = true;
            }
            $seen[$item->id] = true;
        });

        return $changed;
    }

    /**
     * The tree in collection order, flattened for display.
     *
     * @return list<array{id: string, name: string, folder: bool, depth: int, method: ?string, parent: ?string}>
     */
    public function tree(): array
    {
        $rows = [];
        $this->walk($this->data->item, function (\stdClass $item, int $depth, ?\stdClass $parent) use (&$rows) {
            $isFolder = isset($item->item) && is_array($item->item);
            $rows[] = [
                'id' => (string) ($item->id ?? ''),
                'name' => (string) ($item->name ?? 'Unnamed'),
                'folder' => $isFolder,
                'depth' => $depth,
                'method' => $isFolder ? null : $this->method($item),
                'parent' => $parent ? (string) ($parent->id ?? '') : null,
            ];
        });

        return $rows;
    }

    /**
     * Requests only, in the order a full run sends them.
     *
     * @return list<array{id: string, name: string, method: ?string, folder: ?string}>
     */
    public function requests(): array
    {
        $names = [];
        $out = [];
        foreach ($this->tree() as $row) {
            if ($row['folder']) {
                $names[$row['id']] = $row['name'];

                continue;
            }
            $out[] = ['id' => $row['id'], 'name' => $row['name'], 'method' => $row['method'], 'folder' => $row['parent'] !== null ? ($names[$row['parent']] ?? null) : null];
        }

        return $out;
    }

    public function has(string $id): bool
    {
        return $this->find($id) !== null;
    }

    public function isRequest(string $id): bool
    {
        $item = $this->find($id);

        return $item !== null && ! (isset($item->item) && is_array($item->item));
    }

    // --- Tree edits ---

    public function addRequest(?string $parentId, string $name): string
    {
        $item = new \stdClass;
        $item->id = (string) Str::uuid();
        $item->name = $name;
        $item->request = (object) ['method' => 'GET', 'header' => [], 'url' => (object) ['raw' => '']];
        $children = &$this->childrenOf($parentId);
        $children[] = $item;

        return $item->id;
    }

    public function addFolder(?string $parentId, string $name): string
    {
        $item = new \stdClass;
        $item->id = (string) Str::uuid();
        $item->name = $name;
        $item->item = [];
        $children = &$this->childrenOf($parentId);
        $children[] = $item;

        return $item->id;
    }

    public function rename(string $id, string $name): void
    {
        $this->findOrFail($id)->name = $name;
    }

    public function duplicate(string $id): string
    {
        [$list, $index] = $this->locate($id);
        $copy = unserialize(serialize($list[$index]));
        $copy->name = ($copy->name ?? 'Unnamed').' copy';
        $copy->id = (string) Str::uuid();
        if (isset($copy->item) && is_array($copy->item)) {
            $this->walk($copy->item, function (\stdClass $item) {
                $item->id = (string) Str::uuid();
            });
        }

        $this->splice($id, fn (array &$siblings, int $i) => array_splice($siblings, $i + 1, 0, [$copy]));

        return $copy->id;
    }

    public function delete(string $id): void
    {
        $this->splice($id, fn (array &$siblings, int $i) => array_splice($siblings, $i, 1));
    }

    /**
     * Moves an item one place up (-1) or down (+1) among its siblings.
     */
    public function move(string $id, int $direction): void
    {
        $this->splice($id, function (array &$siblings, int $i) use ($direction) {
            $j = $i + ($direction < 0 ? -1 : 1);
            if ($j < 0 || $j >= count($siblings)) {
                return;
            }
            [$siblings[$i], $siblings[$j]] = [$siblings[$j], $siblings[$i]];
        });
    }

    /**
     * Adds a separate `test` script to every request, after its own scripts.
     * Only on a run's temporary copy (VariableCapture), never on the stored file.
     */
    public function appendTestScriptToRequests(string $source): void
    {
        $this->walk($this->data->item, function (\stdClass $item) use ($source) {
            if (isset($item->item) && is_array($item->item)) {
                return;
            }
            $events = is_array($item->event ?? null) ? $item->event : [];
            $events[] = (object) ['listen' => 'test', 'script' => (object) ['type' => 'text/javascript', 'exec' => [$source]]];
            $item->event = $events;
        });
    }

    // --- Request form ---

    /**
     * What the editor shows for one request. Parts the editor cannot write
     * (other auth types, file bodies, GraphQL) are flagged, not shown.
     *
     * @return array<string, mixed>
     */
    public function requestForm(string $id): array
    {
        $item = $this->findOrFail($id);
        $request = $item->request ?? new \stdClass;
        if (is_string($request)) {
            $request = (object) ['method' => 'GET', 'url' => $request];
        }

        return [
            'name' => (string) ($item->name ?? ''),
            'method' => strtoupper((string) ($request->method ?? 'GET')),
            'url' => PostmanUrl::raw($request->url ?? ''),
            'params' => PostmanUrl::params($request->url ?? ''),
            'headers' => $this->rows($request->header ?? []),
            'auth' => $this->authForm($request->auth ?? null),
            'body' => $this->bodyForm($request->body ?? null),
            'prerequest' => $this->script($item, 'prerequest'),
            'test' => $this->script($item, 'test'),
        ];
    }

    /**
     * Writes the editor's form back into the request.
     *
     * @param  array<string, mixed>  $form  as returned by requestForm(), edited
     */
    public function applyRequestForm(string $id, array $form): void
    {
        $item = $this->findOrFail($id);
        if (! isset($item->request) || ! $item->request instanceof \stdClass) {
            $item->request = (object) ['url' => $item->request ?? ''];
        }
        $request = $item->request;

        $item->name = (string) $form['name'];
        $request->method = strtoupper((string) $form['method']);
        $request->url = PostmanUrl::toObject((string) $form['url'], $form['params'], $request->url ?? null);
        $request->header = $this->rowsToPostman($form['headers']);
        $this->applyAuth($request, $form['auth']);
        $this->applyBody($request, $form['body']);
        $this->applyScript($item, 'prerequest', (string) $form['prerequest']);
        $this->applyScript($item, 'test', (string) $form['test']);
    }

    // --- Collection-level, read-only ---

    /**
     * @return array{auth: ?string, prerequest: string, test: string, variables: list<array{key: string, value: string}>}
     */
    public function collectionSummary(): array
    {
        $variables = [];
        foreach ((array) ($this->data->variable ?? []) as $variable) {
            if ($variable instanceof \stdClass && isset($variable->key)) {
                $variables[] = ['key' => (string) $variable->key, 'value' => is_scalar($variable->value ?? null) ? (string) $variable->value : ''];
            }
        }

        return [
            'auth' => isset($this->data->auth->type) ? (string) $this->data->auth->type : null,
            'prerequest' => $this->script($this->data, 'prerequest'),
            'test' => $this->script($this->data, 'test'),
            'variables' => $variables,
        ];
    }

    // --- Internals ---

    /**
     * @param  list<mixed>  $items
     * @param  callable(\stdClass, int, ?\stdClass): void  $visit
     */
    private function walk(array $items, callable $visit, int $depth = 0, ?\stdClass $parent = null): void
    {
        foreach ($items as $item) {
            if (! $item instanceof \stdClass) {
                continue;
            }
            $visit($item, $depth, $parent);
            if (isset($item->item) && is_array($item->item)) {
                $this->walk($item->item, $visit, $depth + 1, $item);
            }
        }
    }

    private function find(string $id): ?\stdClass
    {
        $found = null;
        $this->walk($this->data->item, function (\stdClass $item) use ($id, &$found) {
            if ($found === null && ($item->id ?? null) === $id) {
                $found = $item;
            }
        });

        return $found;
    }

    private function findOrFail(string $id): \stdClass
    {
        return $this->find($id) ?? throw new ImportException('That request or folder is no longer in the collection.');
    }

    /**
     * @return array{0: list<mixed>, 1: int}
     */
    private function locate(string $id): array
    {
        $result = null;
        $search = function (array $list) use ($id, &$search, &$result) {
            foreach ($list as $i => $item) {
                if ($result !== null || ! $item instanceof \stdClass) {
                    continue;
                }
                if (($item->id ?? null) === $id) {
                    $result = [$list, $i];
                } elseif (isset($item->item) && is_array($item->item)) {
                    $search($item->item);
                }
            }
        };
        $search($this->data->item);

        return $result ?? throw new ImportException('That request or folder is no longer in the collection.');
    }

    /**
     * Runs $change on the sibling list that holds $id, by reference.
     *
     * @param  callable(array<int, mixed>&, int): mixed  $change
     */
    private function splice(string $id, callable $change): void
    {
        $apply = function (array &$list) use ($id, $change, &$apply): bool {
            foreach ($list as $i => $item) {
                if (! $item instanceof \stdClass) {
                    continue;
                }
                if (($item->id ?? null) === $id) {
                    $change($list, $i);
                    $list = array_values($list);

                    return true;
                }
                if (isset($item->item) && is_array($item->item) && $apply($item->item)) {
                    return true;
                }
            }

            return false;
        };

        if (! $apply($this->data->item)) {
            throw new ImportException('That request or folder is no longer in the collection.');
        }
    }

    /**
     * @return array<int, mixed>
     */
    private function &childrenOf(?string $parentId): array
    {
        if ($parentId === null) {
            return $this->data->item;
        }

        $parent = $this->findOrFail($parentId);
        if (! isset($parent->item) || ! is_array($parent->item)) {
            throw new ImportException('Requests can only be added to a folder.');
        }

        return $parent->item;
    }

    private function method(\stdClass $item): string
    {
        $request = $item->request ?? null;

        return strtoupper($request instanceof \stdClass ? (string) ($request->method ?? 'GET') : 'GET');
    }

    /**
     * @return list<array{key: string, value: string, enabled: bool, description: string}>
     */
    private function rows(mixed $list): array
    {
        $rows = [];
        foreach ((array) $list as $row) {
            if ($row instanceof \stdClass) {
                $rows[] = [
                    'key' => (string) ($row->key ?? ''),
                    'value' => is_scalar($row->value ?? null) ? (string) $row->value : '',
                    'enabled' => empty($row->disabled),
                    'description' => is_string($row->description ?? null) ? $row->description : '',
                ];
            }
        }

        return $rows;
    }

    /**
     * @param  list<array{key: string, value: string, enabled: bool, description?: string}>  $rows
     * @return list<\stdClass>
     */
    private function rowsToPostman(array $rows): array
    {
        $out = [];
        foreach ($rows as $row) {
            if (trim($row['key']) === '' && trim($row['value']) === '') {
                continue;
            }
            $entry = (object) ['key' => $row['key'], 'value' => $row['value']];
            if (! $row['enabled']) {
                $entry->disabled = true;
            }
            if (($row['description'] ?? '') !== '') {
                $entry->description = $row['description'];
            }
            $out[] = $entry;
        }

        return $out;
    }

    /**
     * @return array<string, mixed>
     */
    private function authForm(mixed $auth): array
    {
        $form = ['type' => 'inherit', 'token' => '', 'username' => '', 'password' => '', 'key' => '', 'value' => '', 'in' => 'header', 'other' => null];

        if (! $auth instanceof \stdClass || ! isset($auth->type)) {
            return $form;
        }

        $type = (string) $auth->type;
        if (! in_array($type, self::AUTH_TYPES, true)) {
            return ['type' => 'other', 'other' => $type] + $form;
        }

        $form['type'] = $type;
        foreach ((array) ($auth->{$type} ?? []) as $field) {
            if ($field instanceof \stdClass && isset($field->key) && array_key_exists((string) $field->key, $form)) {
                $form[(string) $field->key] = is_scalar($field->value ?? null) ? (string) $field->value : '';
            }
        }

        return $form;
    }

    /**
     * @param  array<string, mixed>  $form
     */
    private function applyAuth(\stdClass $request, array $form): void
    {
        $type = (string) ($form['type'] ?? 'inherit');

        if ($type === 'other') {
            return; // Not editable here; the stored block stays.
        }
        if ($type === 'inherit') {
            unset($request->auth);

            return;
        }
        if ($type === 'noauth') {
            $request->auth = (object) ['type' => 'noauth'];

            return;
        }

        $fields = match ($type) {
            'bearer' => ['token'],
            'basic' => ['username', 'password'],
            'apikey' => ['key', 'value', 'in'],
            default => [],
        };

        $request->auth = (object) [
            'type' => $type,
            $type => array_map(fn ($key) => (object) ['key' => $key, 'value' => (string) ($form[$key] ?? ''), 'type' => 'string'], $fields),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function bodyForm(mixed $body): array
    {
        $form = ['mode' => 'none', 'raw' => '', 'language' => 'json', 'fields' => [], 'other' => null];

        if (! $body instanceof \stdClass || ! isset($body->mode)) {
            return $form;
        }

        $mode = (string) $body->mode;

        if ($mode === 'raw') {
            $raw = is_string($body->raw ?? null) ? $body->raw : '';
            if (strlen($raw) > self::RAW_EDIT_MAX_BYTES) {
                return ['mode' => 'other', 'other' => 'a raw body of '.number_format(strlen($raw) / 1024).' KB, too large to edit here'] + $form;
            }
            $language = (string) ($body->options->raw->language ?? 'text');

            return ['mode' => 'raw', 'raw' => is_string($body->raw ?? null) ? $body->raw : '', 'language' => in_array($language, self::RAW_LANGUAGES, true) ? $language : 'text'] + $form;
        }

        if ($mode === 'urlencoded') {
            return ['mode' => 'urlencoded', 'fields' => $this->rows($body->urlencoded ?? [])] + $form;
        }

        if ($mode === 'formdata') {
            foreach ((array) ($body->formdata ?? []) as $field) {
                if ($field instanceof \stdClass && ($field->type ?? 'text') === 'file') {
                    return ['mode' => 'other', 'other' => 'form-data with files'] + $form;
                }
            }

            return ['mode' => 'formdata', 'fields' => $this->rows($body->formdata ?? [])] + $form;
        }

        return ['mode' => 'other', 'other' => $mode] + $form;
    }

    /**
     * @param  array<string, mixed>  $form
     */
    private function applyBody(\stdClass $request, array $form): void
    {
        $mode = (string) ($form['mode'] ?? 'none');

        if ($mode === 'other') {
            return; // Not editable here; the stored body stays.
        }
        if ($mode === 'none') {
            unset($request->body);

            return;
        }

        if ($mode === 'raw') {
            $language = in_array($form['language'] ?? 'text', self::RAW_LANGUAGES, true) ? $form['language'] : 'text';
            $request->body = (object) [
                'mode' => 'raw',
                'raw' => (string) ($form['raw'] ?? ''),
                'options' => (object) ['raw' => (object) ['language' => $language]],
            ];

            return;
        }

        $fields = $this->rowsToPostman($form['fields'] ?? []);
        if ($mode === 'formdata') {
            foreach ($fields as $field) {
                $field->type = 'text';
            }
        }
        $request->body = (object) ['mode' => $mode, $mode => $fields];
    }

    private function script(\stdClass $owner, string $listen): string
    {
        foreach ((array) ($owner->event ?? []) as $event) {
            if ($event instanceof \stdClass && ($event->listen ?? null) === $listen) {
                $exec = $event->script->exec ?? '';

                return is_array($exec) ? implode("\n", array_map('strval', $exec)) : (string) $exec;
            }
        }

        return '';
    }

    private function applyScript(\stdClass $item, string $listen, string $source): void
    {
        $source = str_replace("\r\n", "\n", $source);
        $events = is_array($item->event ?? null) ? $item->event : [];

        foreach ($events as $i => $event) {
            if ($event instanceof \stdClass && ($event->listen ?? null) === $listen) {
                if (trim($source) === '') {
                    unset($events[$i]);
                } else {
                    $event->script ??= new \stdClass;
                    $event->script->type ??= 'text/javascript';
                    $event->script->exec = explode("\n", $source);
                }
                $item->event = array_values($events);

                return;
            }
        }

        if (trim($source) !== '') {
            $events[] = (object) ['listen' => $listen, 'script' => (object) ['type' => 'text/javascript', 'exec' => explode("\n", $source)]];
            $item->event = $events;
        }
    }
}
