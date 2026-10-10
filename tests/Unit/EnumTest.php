<?php

namespace Tests\Unit;

use App\Enums\CollectionKind;
use App\Enums\EnvironmentType;
use App\Enums\RunStatus;
use App\Enums\RunTrigger;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class EnumTest extends TestCase
{
    /**
     * Every enum value must fit the VARCHAR it is stored in, or MySQL strict
     * mode rejects the insert at runtime.
     *
     * @return array<string, array{class-string<\BackedEnum>, int}>
     */
    public static function columnWidths(): array
    {
        return [
            'runs.status' => [RunStatus::class, 12],
            'runs.trigger' => [RunTrigger::class, 8],
            'environments.type' => [EnvironmentType::class, 20],
            'collections.kind' => [CollectionKind::class, 20],
        ];
    }

    /**
     * @param  class-string<\BackedEnum>  $enum
     */
    #[DataProvider('columnWidths')]
    public function test_enum_values_fit_their_column(string $enum, int $width): void
    {
        foreach ($enum::cases() as $case) {
            $this->assertLessThanOrEqual($width, strlen($case->value), $enum.'::'.$case->name);
        }
    }

    public function test_only_queued_and_running_are_active(): void
    {
        $active = array_filter(RunStatus::cases(), fn (RunStatus $status) => $status->isActive());

        $this->assertSame([RunStatus::Queued, RunStatus::Running], array_values($active));
        $this->assertTrue(RunStatus::Cancelled->isFinal());
    }

    public function test_only_production_is_production(): void
    {
        foreach (EnvironmentType::cases() as $type) {
            $this->assertSame($type === EnvironmentType::Production, $type->isProduction());
        }
    }
}
