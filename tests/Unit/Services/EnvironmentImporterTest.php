<?php

namespace Tests\Unit\Services;

use App\Exceptions\ImportException;
use App\Models\Environment;
use App\Models\EnvironmentVariable;
use App\Services\EnvironmentImporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class EnvironmentImporterTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @param  list<array<string, mixed>>  $values
     */
    private function file(array $values): string
    {
        return json_encode(['name' => 'Env', 'values' => $values]);
    }

    public function test_it_reads_both_secret_and_disabled_conventions(): void
    {
        $parsed = (new EnvironmentImporter)->parse($this->file([
            ['key' => 'a', 'value' => '1', 'type' => 'secret'],                 // Postman app export
            ['key' => 'b', 'value' => '2', 'secret' => true],                    // postman-collection Variable
            ['key' => 'c', 'value' => '3', 'type' => 'default', 'enabled' => false],
            ['key' => 'd', 'value' => '4', 'disabled' => true],
            ['key' => 'e', 'value' => '', 'type' => 'default'],
            ['key' => 'f', 'value' => true],
        ]));

        $this->assertSame([true, true, false, false, false, false], array_column($parsed, 'is_secret'));
        $this->assertSame([true, true, false, false, true, true], array_column($parsed, 'enabled'));
        $this->assertNull($parsed[4]['value'], 'empty string becomes null');
        $this->assertSame('true', $parsed[5]['value']);
    }

    public function test_credential_looking_keys_are_marked_secret(): void
    {
        $parsed = (new EnvironmentImporter)->parse($this->file([
            ['key' => 'password', 'value' => 'x'], ['key' => 'client_secret', 'value' => 'x'],
            ['key' => 'access_token', 'value' => 'x'], ['key' => 'apiKey', 'value' => 'x'],
            ['key' => 'base_url', 'value' => 'x'],
        ]));

        $this->assertSame([true, true, true, true, false], array_column($parsed, 'is_secret'));
        $this->assertSame([true, true, true, true, false], array_column($parsed, 'secret_by_name'));
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function badFiles(): array
    {
        return [
            'not json' => ['{', 'not valid JSON'],
            'no values' => ['{"name":"x"}', 'no "values" list'],
            'collection' => ['{"info":{"schema":"https://schema.getpostman.com/json/collection/v2.1.0/collection.json"},"item":[],"values":[]}', 'collection'],
            'missing key' => ['{"values":[{"value":"x"}]}', '#1 has no key'],
            'bad key' => ['{"values":[{"key":"a b","value":"x"}]}', 'unsupported key'],
            'duplicate' => ['{"values":[{"key":"a"},{"key":"a"}]}', 'more than once'],
            'object value' => ['{"values":[{"key":"a","value":{"x":1}}]}', 'not text'],
        ];
    }

    #[DataProvider('badFiles')]
    public function test_bad_files_are_rejected_with_a_clear_message(string $json, string $message): void
    {
        $this->expectException(ImportException::class);
        $this->expectExceptionMessage($message);

        (new EnvironmentImporter)->parse($json);
    }

    public function test_error_messages_never_contain_values(): void
    {
        try {
            (new EnvironmentImporter)->parse($this->file([['key' => 'k', 'value' => str_repeat('S', EnvironmentVariable::MAX_VALUE_LENGTH + 1)]]));
            $this->fail('expected an ImportException');
        } catch (ImportException $e) {
            $this->assertStringNotContainsString('SSSS', $e->getMessage());
        }
    }

    public function test_import_adds_updates_keeps_and_never_downgrades_a_secret(): void
    {
        $environment = Environment::factory()->create();
        $environment->variables()->createMany([
            ['key' => 'base_url', 'value' => 'https://old.example.invalid', 'is_secret' => false],
            ['key' => 'api_key', 'value' => 'YOUR_API_KEY', 'is_secret' => true],
            ['key' => 'password', 'value' => 'YOUR_PASSWORD', 'is_secret' => true],
        ]);

        $counts = (new EnvironmentImporter)->import($environment, $this->file([
            ['key' => 'base_url', 'value' => 'https://new.example.invalid'],
            ['key' => 'api_key', 'value' => 'YOUR_NEW_KEY', 'type' => 'default'], // file says not secret
            ['key' => 'password', 'value' => ''],                                // empty: keep stored
            ['key' => 'timeout', 'value' => '30'],
            ['key' => 'session_id', 'value' => 'x'],                           // new, secret by name
        ]));

        $this->assertSame(['added' => 2, 'updated' => 2, 'kept' => 1, 'secret_by_name' => 1], $counts);
        $variables = $environment->variables()->get()->keyBy('key');
        $this->assertSame('https://new.example.invalid', $variables['base_url']->value);
        $this->assertSame('YOUR_NEW_KEY', $variables['api_key']->value);
        $this->assertTrue($variables['api_key']->is_secret, 'never downgraded');
        $this->assertSame('YOUR_PASSWORD', $variables['password']->value);
        $this->assertSame('30', $variables['timeout']->value);
    }

    public function test_a_bad_file_imports_nothing(): void
    {
        $environment = Environment::factory()->create();

        try {
            (new EnvironmentImporter)->import($environment, $this->file([['key' => 'ok', 'value' => '1'], ['key' => 'bad key']]));
        } catch (ImportException) {
        }

        $this->assertSame(0, $environment->variables()->count());
    }
}
