<?php

namespace Tests\Feature\Capture;

use App\Livewire\Captures;
use App\Models\CaptureBucket;
use App\Models\CapturedRequest;
use App\Models\Environment;
use App\Services\CaptureRecorder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Outbound capture (plan section 13, loopholes 38–44, decisions.md D-032).
 */
class CaptureTest extends TestCase
{
    use RefreshDatabase;

    private const API_TOKEN = 'YOUR_ACCESS_TOKEN_0123456789abcdefghijklmnopqrstuvwxyz';

    /**
     * @return array{CaptureBucket, string}
     */
    private function bucket(array $attributes = []): array
    {
        $bucket = CaptureBucket::factory()->make($attributes);
        $token = $bucket->issueToken();
        $bucket->save();

        return [$bucket, $token];
    }

    public function test_an_enabled_bucket_records_the_call_and_replies_with_its_status(): void
    {
        [$bucket, $token] = $this->bucket(['response_status' => 202]);

        $this->postJson("/capture/{$bucket->id}/{$token}/orders/paid?ref=42", ['order' => 7, 'status' => 'paid'], ['X-Signature-Id' => 'abc'])
            ->assertStatus(202)->assertExactJson(['received' => true]);

        $captured = CapturedRequest::sole();
        $this->assertSame('POST', $captured->method);
        $this->assertSame('/orders/paid', $captured->path, 'the token is never stored');
        $this->assertSame('ref=42', $captured->query);
        $this->assertSame(['order' => 7, 'status' => 'paid'], json_decode($captured->body, true));
        $this->assertSame('abc', $captured->headers['x-signature-id']);
        $this->assertStringNotContainsString($token, json_encode($captured->toArray()));
    }

    public function test_unknown_disabled_and_wrong_token_all_look_the_same(): void
    {
        [$bucket, $token] = $this->bucket();
        [$disabled, $disabledToken] = $this->bucket(['enabled' => false]);

        foreach ([
            "/capture/{$bucket->id}/YOUR_WRONG_TOKEN",
            "/capture/999999/{$token}",
            "/capture/abc/{$token}",
            "/capture/{$disabled->id}/{$disabledToken}",
        ] as $uri) {
            $this->post($uri, ['x' => 1])->assertNotFound()->assertExactJson(['message' => 'Not Found']);
        }

        $this->assertSame(0, CapturedRequest::count());
    }

    public function test_no_csrf_or_session_is_needed(): void
    {
        [$bucket, $token] = $this->bucket();

        // A plain form post, as another system would send it, without a CSRF token.
        $this->call('POST', "/capture/{$bucket->id}/{$token}", ['a' => 'b'])->assertOk();
        $this->assertSame(1, CapturedRequest::count());
    }

    public function test_credentials_are_kept_as_present_only_and_secrets_are_redacted(): void
    {
        [$bucket, $token] = $this->bucket();
        $bucket->environment->variables()->create(['key' => 'gateway_key', 'value' => 'YOUR_GATEWAY_KEY', 'is_secret' => true]);

        $this->postJson("/capture/{$bucket->id}/{$token}?api_key=YOUR_API_KEY", [
            'key' => 'YOUR_GATEWAY_KEY',
            'password' => 'YOUR_PASSWORD',
            'amount' => 10,
        ], [
            'Authorization' => 'Bearer YOUR_ACCESS_TOKEN',
            'X-Api-Key' => 'YOUR_API_KEY',
            'Cookie' => 'session=YOUR_SESSION',
        ])->assertOk();

        $captured = CapturedRequest::sole();
        $this->assertSame(CaptureRecorder::PRESENT, $captured->headers['authorization']);
        $this->assertSame(CaptureRecorder::PRESENT, $captured->headers['x-api-key']);
        $this->assertSame(CaptureRecorder::PRESENT, $captured->headers['cookie']);
        $this->assertSame('api_key=********', $captured->query);
        $this->assertSame(['key' => '********', 'password' => '********', 'amount' => 10], json_decode($captured->body, true));
        $this->assertStringNotContainsString('YOUR_', json_encode($captured->toArray()));
    }

    public function test_large_bodies_are_cut_and_binary_bodies_are_not_stored(): void
    {
        [$bucket, $token] = $this->bucket();

        $this->call('POST', "/capture/{$bucket->id}/{$token}", [], [], [], ['CONTENT_TYPE' => 'text/plain'], str_repeat('a', CaptureRecorder::MAX_BODY_BYTES + 10))->assertOk();
        $this->call('POST', "/capture/{$bucket->id}/{$token}", [], [], [], ['CONTENT_TYPE' => 'application/octet-stream'], "\xff\xfe\x00binary")->assertOk();

        [$large, $binary] = CapturedRequest::orderBy('id')->get();
        $this->assertTrue($large->body_truncated);
        $this->assertSame(CaptureRecorder::MAX_BODY_BYTES, strlen($large->body));
        $this->assertStringStartsWith('[binary body, ', $binary->body);
    }

    public function test_old_records_expire(): void
    {
        [$bucket, $token] = $this->bucket(['expires_after_hours' => 1]);
        $bucket->requests()->create(['method' => 'GET', 'path' => '/old', 'headers' => [], 'received_at' => now()->subHours(2)]);

        $this->get("/capture/{$bucket->id}/{$token}/new")->assertOk();

        $this->assertSame(['/new'], $bucket->requests()->pluck('path')->all());
    }

    public function test_capture_urls_are_rate_limited_per_bucket(): void
    {
        [$bucket, $token] = $this->bucket();
        [$other, $otherToken] = $this->bucket();

        for ($i = 0; $i < 120; $i++) {
            $this->get("/capture/{$bucket->id}/{$token}")->assertOk();
        }
        $this->get("/capture/{$bucket->id}/{$token}")->assertStatus(429);
        $this->get("/capture/{$other->id}/{$otherToken}")->assertOk();
    }

    public function test_production_buckets_are_impossible(): void
    {
        $production = Environment::factory()->production()->create();

        $this->expectException(\LogicException::class);
        $this->bucket(['environment_id' => $production->id, 'project_id' => $production->project_id]);
    }

    public function test_the_api_returns_captures_since_a_time_with_parsed_json(): void
    {
        config(['automation.api_token' => self::API_TOKEN]);
        [$bucket, $token] = $this->bucket(['slug' => 'payments']);
        $bucket->requests()->create(['method' => 'POST', 'path' => '/old', 'headers' => [], 'body' => '{"n":1}', 'received_at' => now()->subMinutes(5)]);
        $since = now()->subMinute()->toIso8601ZuluString();
        $this->postJson("/capture/{$bucket->id}/{$token}/new", ['n' => 2])->assertOk();

        $url = "/api/v1/captures/{$bucket->project->slug}/payments";
        $this->getJson($url)->assertUnauthorized();

        $this->withHeader('Authorization', 'Bearer '.self::API_TOKEN)->getJson($url.'?since='.urlencode($since))
            ->assertOk()
            ->assertJsonPath('bucket.slug', 'payments')
            ->assertJsonCount(1, 'captures')
            ->assertJsonPath('captures.0.path', '/new')
            ->assertJsonPath('captures.0.json', ['n' => 2]);

        $this->withHeader('Authorization', 'Bearer '.self::API_TOKEN)->getJson($url)->assertJsonCount(2, 'captures');
        $this->withHeader('Authorization', 'Bearer '.self::API_TOKEN)->getJson("/api/v1/captures/{$bucket->project->slug}/nope")->assertNotFound();
        $this->withHeader('Authorization', 'Bearer '.self::API_TOKEN)->getJson($url.'?since=not-a-date')->assertUnprocessable();
    }

    public function test_the_page_creates_a_disabled_bucket_and_shows_its_url_once(): void
    {
        $environment = Environment::factory()->create(['type' => 'staging']);
        Environment::factory()->production()->for($environment->project)->create();

        $page = Livewire::test(Captures::class, ['project' => $environment->project])
            ->assertSee($environment->name)
            ->assertDontSee('PRODUCTION)')
            ->set('name', 'Payment webhook')
            ->call('createBucket')
            ->assertHasNoErrors()
            ->assertSee('shown only once');

        $bucket = CaptureBucket::sole();
        $this->assertFalse($bucket->enabled);
        $this->assertSame('payment-webhook', $bucket->slug);

        $path = $page->get('revealedPath');
        $this->assertMatchesRegularExpression('#^/capture/'.$bucket->id.'/[A-Za-z0-9]{40}$#', $path);
        $this->assertTrue($bucket->tokenMatches(basename($path)));

        // A fresh page load never shows it again.
        Livewire::test(Captures::class, ['project' => $environment->project])->assertDontSee(basename($path));
    }

    public function test_the_page_refuses_production_even_if_the_browser_sends_it(): void
    {
        $production = Environment::factory()->production()->create();

        Livewire::test(Captures::class, ['project' => $production->project])
            ->assertSee('cannot be created for production')
            ->set('name', 'x')->set('environmentId', $production->id)
            ->call('createBucket')
            ->assertHasErrors('environmentId');
        $this->assertSame(0, CaptureBucket::count());
    }

    public function test_enable_reissue_clear_and_delete(): void
    {
        [$bucket, $token] = $this->bucket(['enabled' => false]);
        $page = Livewire::test(Captures::class, ['project' => $bucket->project]);

        $page->call('setEnabled', $bucket->id, true);
        $this->assertTrue($bucket->fresh()->enabled);
        $this->post("/capture/{$bucket->id}/{$token}")->assertOk();

        $page->call('reissueToken', $bucket->id)->assertSee('The old one stopped working');
        $this->post("/capture/{$bucket->id}/{$token}")->assertNotFound();
        $this->assertTrue($bucket->fresh()->tokenMatches(basename($page->get('revealedPath'))));

        $page->call('clearRequests', $bucket->id);
        $this->assertSame(0, $bucket->requests()->count());

        $page->call('deleteBucket', $bucket->id);
        $this->assertModelMissing($bucket);
    }

    public function test_another_projects_bucket_cannot_be_touched(): void
    {
        [$theirs] = $this->bucket();
        $mine = Environment::factory()->create();

        Livewire::test(Captures::class, ['project' => $mine->project])->call('deleteBucket', $theirs->id)->assertNotFound();
        $this->assertModelExists($theirs);
    }
}
