<?php

namespace Tests\Feature\Ui;

use App\Enums\EnvironmentType;
use App\Enums\RunStatus;
use Illuminate\Support\Facades\Blade;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class BadgeTest extends TestCase
{
    /**
     * @return array<string, array{RunStatus}>
     */
    public static function statuses(): array
    {
        return collect(RunStatus::cases())->mapWithKeys(fn (RunStatus $s) => [$s->value => [$s]])->all();
    }

    #[DataProvider('statuses')]
    public function test_status_badge_shows_icon_and_word_never_colour_alone(RunStatus $status): void
    {
        $html = Blade::render('<x-status-badge :status="$status" />', ['status' => $status]);

        $this->assertStringContainsString('<span aria-hidden="true">'.$status->icon().'</span>'.$status->value, $html);
        $this->assertStringNotContainsString('NO TESTS', $html);
    }

    public function test_status_badge_accepts_the_stored_string(): void
    {
        $this->assertStringContainsString('✕</span>FAIL', Blade::render('<x-status-badge status="FAIL" />'));
    }

    public function test_status_badge_adds_a_no_tests_warning(): void
    {
        $html = Blade::render('<x-status-badge status="PASS" :no-tests="true" />');

        $this->assertStringContainsString('PASS', $html);
        $this->assertStringContainsString('NO TESTS', $html);
    }

    public function test_production_badge_is_unmissable_and_others_are_plain(): void
    {
        $this->assertStringContainsString('⚠</span>PRODUCTION',
            Blade::render('<x-environment-badge :type="$t" />', ['t' => EnvironmentType::Production]));

        $staging = Blade::render('<x-environment-badge type="staging" />');
        $this->assertStringContainsString('staging', $staging);
        $this->assertStringNotContainsString('PRODUCTION', $staging);
    }
}
