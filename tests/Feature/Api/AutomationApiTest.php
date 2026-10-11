<?php

namespace Tests\Feature\Api;

use App\Livewire\Settings;
use Illuminate\Support\Facades\Route;
use Livewire\Livewire;
use Tests\TestCase;

class AutomationApiTest extends TestCase
{
    private const TOKEN = 'YOUR_ACCESS_TOKEN_0123456789abcdefghijklmnopqrstuvwxyz';

    private function bearer(string $token = self::TOKEN): array
    {
        return ['Authorization' => 'Bearer '.$token];
    }

    public function test_the_api_is_disabled_until_a_token_is_set(): void
    {
        config(['automation.api_token' => null]);

        $this->getJson('/api/v1/ping', $this->bearer())
            ->assertStatus(503)
            ->assertJsonPath('message', fn ($m) => str_contains($m, 'automation:token'));
    }

    public function test_missing_or_wrong_tokens_are_refused(): void
    {
        config(['automation.api_token' => self::TOKEN]);

        $this->getJson('/api/v1/ping')->assertUnauthorized()->assertHeader('WWW-Authenticate', 'Bearer');
        $this->getJson('/api/v1/ping', $this->bearer('YOUR_WRONG_TOKEN'))->assertUnauthorized();
        $this->getJson('/api/v1/ping', $this->bearer(self::TOKEN.'x'))->assertUnauthorized();
        $this->getJson('/api/v1/ping', ['Authorization' => 'Basic '.base64_encode('a:'.self::TOKEN)])->assertUnauthorized();
    }

    public function test_the_right_token_is_accepted(): void
    {
        config(['automation.api_token' => self::TOKEN]);

        $this->getJson('/api/v1/ping', $this->bearer())->assertOk()->assertExactJson(['ok' => true]);
    }

    public function test_the_api_is_rate_limited(): void
    {
        config(['automation.api_token' => self::TOKEN]);

        for ($i = 0; $i < 60; $i++) {
            $this->getJson('/api/v1/ping', $this->bearer())->assertOk();
        }
        $this->getJson('/api/v1/ping', $this->bearer())->assertStatus(429);
    }

    public function test_api_errors_never_show_traces_even_with_debug_on(): void
    {
        config(['app.debug' => true, 'automation.api_token' => self::TOKEN]);
        Route::middleware('api')->get('/api/v1/_boom', fn () => throw new \RuntimeException('secret detail in /var/www/x.php'));
        Route::middleware('api')->get('/capture/_boom', fn () => throw new \RuntimeException('secret detail'));

        $notFound = $this->getJson('/api/v1/nope', $this->bearer())->assertNotFound()->assertExactJson(['message' => 'Not Found']);
        $this->assertStringNotContainsString('trace', $notFound->getContent());

        foreach (['/api/v1/_boom', '/capture/_boom'] as $uri) {
            $boom = $this->get($uri)->assertStatus(500)->assertExactJson(['message' => 'Internal Server Error']);
            $this->assertStringNotContainsString('secret detail', $boom->getContent());
        }
    }

    public function test_settings_shows_whether_the_api_is_on_but_never_the_token(): void
    {
        config(['automation.api_token' => null]);
        Livewire::test(Settings::class)->assertSee('Disabled')->assertSee('automation:token');

        config(['automation.api_token' => self::TOKEN]);
        Livewire::test(Settings::class)
            ->assertSee('Enabled')
            ->assertSee('automation:token --force')
            ->assertDontSee(self::TOKEN)
            ->assertDontSee('shorter than');

        config(['automation.api_token' => 'YOUR_SHORT']);
        Livewire::test(Settings::class)->assertSee('shorter than');
    }

    public function test_settings_page_renders(): void
    {
        $this->get('/settings')->assertOk()->assertSee('<title>Settings · API Automation</title>', false)->assertSee('Runner');
    }
}
