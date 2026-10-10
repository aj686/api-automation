<?php

namespace Tests\Unit\Services;

use App\Exceptions\ImportException;
use App\Models\Collection;
use App\Models\Project;
use App\Services\CollectionImporter;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class CollectionImporterTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake(CollectionImporter::DISK);
    }

    /**
     * A minimal Postman v2.1 collection.
     *
     * @param  list<array<string, mixed>>|null  $items
     * @param  array<string, mixed>  $extra
     */
    public static function collection(?array $items = null, array $extra = [], string $version = 'v2.1.0'): string
    {
        return json_encode([
            'info' => [
                'name' => 'Demo Smoke',
                'schema' => "https://schema.getpostman.com/json/collection/{$version}/collection.json",
            ],
            'item' => $items ?? [
                ['name' => 'Health', 'request' => ['method' => 'GET', 'url' => ['raw' => '{{base_url}}/health']]],
                ['name' => 'Folder', 'item' => [
                    ['name' => 'List', 'request' => ['method' => 'GET', 'url' => '{{base_url}}/items']],
                ]],
            ],
            ...$extra,
        ]);
    }

    public function test_inspect_reads_name_version_and_counts_nested_requests(): void
    {
        $info = (new CollectionImporter)->inspect(self::collection());

        $this->assertSame('Demo Smoke', $info['name']);
        $this->assertSame('v2.1.0', $info['schema_version']);
        $this->assertSame(2, $info['requests']);
        $this->assertSame([], $info['warnings']);

        $this->assertSame('v2.0.0', (new CollectionImporter)->inspect(self::collection(version: 'v2.0.0'))['schema_version']);
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function badFiles(): array
    {
        return [
            'not json' => ['{', 'not valid JSON'],
            'environment' => ['{"name":"x","values":[]}', 'environment, not a collection'],
            'v1 (no schema)' => ['{"id":"x","name":"old","requests":[]}', 'no info.schema'],
            'unknown schema' => ['{"info":{"name":"x","schema":"https://schema.getpostman.com/json/collection/v1.0.0/collection.json"},"item":[]}', 'Unsupported collection format'],
            'no name' => ['{"info":{"schema":"https://schema.getpostman.com/json/collection/v2.1.0/collection.json"},"item":[]}', 'no name'],
            'no items' => ['{"info":{"name":"x","schema":"https://schema.getpostman.com/json/collection/v2.1.0/collection.json"}}', 'no requests'],
        ];
    }

    #[DataProvider('badFiles')]
    public function test_bad_files_are_rejected(string $json, string $message): void
    {
        $this->expectException(ImportException::class);
        $this->expectExceptionMessage($message);

        (new CollectionImporter)->inspect($json);
    }

    public function test_localhost_requests_are_warned_about(): void
    {
        $warnings = (new CollectionImporter)->inspect(self::collection([
            ['name' => 'a', 'request' => ['url' => 'http://localhost:8000/x']],
            ['name' => 'b', 'request' => ['url' => ['raw' => 'http://127.0.0.1/x']]],
            ['name' => 'c', 'request' => ['url' => '{{base_url}}/localhost-docs']], // not a host
        ]))['warnings'];

        $this->assertCount(1, $warnings);
        $this->assertStringStartsWith('2 requests call localhost', $warnings[0]);
    }

    public function test_literal_credentials_are_counted_but_never_echoed(): void
    {
        $literal = 'YOUR_ACCESS_TOKEN_literal';
        $warnings = (new CollectionImporter)->inspect(self::collection(
            [
                ['name' => 'a', 'request' => [
                    'url' => '{{base_url}}/a',
                    'auth' => ['type' => 'bearer', 'bearer' => [['key' => 'token', 'value' => $literal]]],
                    'header' => [['key' => 'Authorization', 'value' => 'Bearer '.$literal]],
                ]],
                ['name' => 'b', 'request' => [
                    'url' => '{{base_url}}/b',
                    'auth' => ['type' => 'bearer', 'bearer' => [['key' => 'token', 'value' => '{{access_token}}']]],
                    'header' => [['key' => 'Authorization', 'value' => 'Bearer {{access_token}}']],
                ]],
            ],
            [
                'auth' => ['type' => 'basic', 'basic' => [['key' => 'username', 'value' => 'me'], ['key' => 'password', 'value' => $literal]]],
                'variable' => [['key' => 'api_key', 'value' => $literal], ['key' => 'base_url', 'value' => 'https://x.example.invalid']],
            ],
        ))['warnings'];

        $this->assertCount(1, $warnings);
        $this->assertStringStartsWith('4 credentials typed directly', $warnings[0]);
        $this->assertStringNotContainsString($literal, $warnings[0]);
    }

    public function test_store_keeps_the_file_byte_for_byte_under_a_generated_path(): void
    {
        $project = Project::factory()->create();
        $json = self::collection();

        $collection = (new CollectionImporter)->store($project, $json, '../../etc/Demo.postman_collection.json');

        $this->assertMatchesRegularExpression('#^collections/[0-9a-z]{26}\.json$#', $collection->stored_path);
        $this->assertSame($json, Storage::disk('local')->get($collection->stored_path));
        $this->assertSame(hash('sha256', $json), $collection->sha256);
        $this->assertSame('Demo.postman_collection.json', $collection->original_filename, 'basename only');
        $this->assertSame('demo-smoke', $collection->slug);
        $this->assertSame('v2.1.0', $collection->schema_version);
    }

    public function test_a_failed_store_leaves_no_file(): void
    {
        $project = Project::factory()->create();
        Collection::factory()->for($project)->create(['slug' => 'demo-smoke']);

        try {
            (new CollectionImporter)->store($project, self::collection(), 'x.json');
            $this->fail('expected a unique-slug failure');
        } catch (QueryException) {
        }

        $this->assertSame([], Storage::disk('local')->allFiles('collections'));
    }

    public function test_replace_swaps_the_file_and_keeps_the_record(): void
    {
        $importer = new CollectionImporter;
        $collection = $importer->store(Project::factory()->create(), self::collection(), 'v1.json');
        $oldPath = $collection->stored_path;

        $newJson = self::collection([['name' => 'Only', 'request' => ['url' => '{{base_url}}/only']]]);
        $importer->replace($collection, $newJson, 'v2.json');

        $collection->refresh();
        $this->assertNotSame($oldPath, $collection->stored_path);
        $this->assertFalse(Storage::disk('local')->exists($oldPath));
        $this->assertSame($newJson, Storage::disk('local')->get($collection->stored_path));
        $this->assertSame('demo-smoke', $collection->slug);
        $this->assertSame('v2.json', $collection->original_filename);
    }

    public function test_deleting_a_collection_or_its_project_deletes_the_files(): void
    {
        $importer = new CollectionImporter;
        $project = Project::factory()->create();
        $one = $importer->store($project, self::collection(), 'a.json', ['slug' => 'one']);
        $importer->store($project, self::collection(), 'b.json', ['slug' => 'two']);

        $one->delete();
        $this->assertCount(1, Storage::disk('local')->allFiles('collections'));

        $project->delete();
        $this->assertSame([], Storage::disk('local')->allFiles('collections'));
    }
}
