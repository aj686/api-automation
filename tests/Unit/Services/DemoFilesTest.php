<?php

namespace Tests\Unit\Services;

use App\Services\CollectionImporter;
use App\Services\EnvironmentImporter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The files in demo/ are what users import first: they must stay valid,
 * warning-free and free of real credentials.
 */
class DemoFilesTest extends TestCase
{
    private static function path(string $file): string
    {
        return __DIR__.'/../../../demo/'.$file;
    }

    /**
     * @return array<string, array{string}>
     */
    public static function collections(): array
    {
        return array_combine(
            array_map('basename', glob(self::path('collections/*.json'))),
            array_map(fn ($f) => [$f], glob(self::path('collections/*.json'))),
        );
    }

    /**
     * @return array<string, array{string}>
     */
    public static function environments(): array
    {
        return array_combine(
            array_map('basename', glob(self::path('environments/*.json'))),
            array_map(fn ($f) => [$f], glob(self::path('environments/*.json'))),
        );
    }

    #[DataProvider('collections')]
    public function test_demo_collections_import_without_warnings(string $file): void
    {
        $info = (new CollectionImporter)->inspect(file_get_contents($file));

        $this->assertSame([], $info['warnings']);
        $this->assertGreaterThan(0, $info['requests']);
    }

    #[DataProvider('environments')]
    public function test_demo_environments_hold_placeholders_only(string $file): void
    {
        $variables = (new EnvironmentImporter)->parse(file_get_contents($file));
        $this->assertNotEmpty($variables);

        foreach ($variables as $variable) {
            if ($variable['is_secret']) {
                $this->assertStringStartsWith('YOUR_', (string) $variable['value'], $variable['key']);
            }
        }
    }
}
