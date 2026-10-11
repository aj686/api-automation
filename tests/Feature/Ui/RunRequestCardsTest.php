<?php

namespace Tests\Feature\Ui;

use App\Enums\RunStatus;
use App\Livewire\RunShow;
use App\Models\Run;
use App\Models\RunRequest;
use App\Models\RunResult;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Postman-style request cards on the run page (decisions.md D-037).
 */
class RunRequestCardsTest extends TestCase
{
    use RefreshDatabase;

    private function card(Run $run, array $attributes = [], array $results = [['assertion' => 'status is 200', 'passed' => true]]): RunRequest
    {
        $request = $run->requests()->create([
            'position' => $run->requests()->count(),
            'item_name' => 'List orders',
            'method' => 'GET',
            'url' => 'http://nginx/api/orders',
            'request_headers' => [['name' => 'Authorization', 'value' => 'Bearer ********']],
            'request_body' => null,
            'response_code' => 200,
            'response_status' => 'OK',
            'response_headers' => [['name' => 'Content-Type', 'value' => 'application/json']],
            'response_body' => '{"data":[{"order_id":7}]}',
            'response_content_type' => 'application/json',
            'response_size' => 26,
            'response_time_ms' => 42,
            ...$attributes,
        ]);
        foreach ($results as $i => $result) {
            RunResult::factory()->for($run)->create(['run_request_id' => $request->id, 'position' => $i, 'item_name' => $request->item_name, ...$result]);
        }

        return $request;
    }

    public function test_the_response_body_is_on_the_page_without_any_interaction(): void
    {
        $run = Run::factory()->passed(1)->create();
        $this->card($run);

        $this->get(route('runs.show', $run))
            ->assertOk()
            ->assertSee('List orders')
            ->assertSee('200 OK')
            ->assertSee('42 ms')
            ->assertSee('1/1 tests')
            // Pretty-printed JSON, rendered server-side.
            ->assertSee('"order_id": 7')
            ->assertSee('Response headers')
            ->assertSee('Bearer ********');
    }

    public function test_a_failed_request_opens_every_section_with_its_failure(): void
    {
        $run = Run::factory()->failed(1, 1)->create();
        $this->card($run, ['method' => 'POST', 'item_name' => 'Create order', 'response_code' => 500, 'response_status' => 'Internal Server Error',
            'request_body' => '{"order":7}', 'response_body' => '{"message":"Server Error"}'],
            [['assertion' => 'Status code is 201', 'passed' => false, 'error_message' => 'expected 201 but got 500']]);

        Livewire::test(RunShow::class, ['run' => $run])
            ->assertSee('Failed')
            ->assertSee('expected 201 but got 500')
            ->assertSeeHtml('open: true, expanded: true')
            ->assertDontSeeHtml('role="tablist"')
            ->assertSee('Request body')
            ->assertSee('"order": 7');
    }

    public function test_errored_binary_and_purged_cards(): void
    {
        $run = Run::factory()->create(['status' => RunStatus::Error]);
        $this->card($run, ['item_name' => 'Nowhere', 'response_code' => null, 'response_status' => null, 'response_body' => null, 'error_message' => 'getaddrinfo ENOTFOUND x.invalid'],
            [['assertion' => 'status is 200', 'passed' => false]]);
        $this->card($run, ['item_name' => 'Logo', 'response_body' => null, 'response_body_binary' => true, 'response_content_type' => 'image/png', 'response_size' => 2048]);
        $this->card($run, ['item_name' => 'Old', 'response_body' => null, 'response_headers' => null, 'request_headers' => null, 'payload_purged_at' => now()]);

        Livewire::test(RunShow::class, ['run' => $run])
            ->assertSee('no response')
            ->assertSee('getaddrinfo ENOTFOUND x.invalid')
            ->assertSee('Binary body (image/png, 2.0 KB)')
            ->assertSee('Payload deleted after 30 days.');
    }

    public function test_filters_work_per_request(): void
    {
        $run = Run::factory()->failed(2, 1)->create();
        $this->card($run, ['item_name' => 'Fine one']);
        $this->card($run, ['item_name' => 'Broken one'], [['assertion' => 'x', 'passed' => false]]);
        $this->card($run, ['item_name' => 'Unreachable', 'error_message' => 'ECONNREFUSED'], []);

        $page = Livewire::test(RunShow::class, ['run' => $run]);
        $page->assertSee('Fine one')->assertSee('Broken one')->assertSee('Unreachable');
        $page->set('resultFilter', 'passed')->assertSee('Fine one')->assertDontSee('Broken one')->assertDontSee('Unreachable');
        $page->set('resultFilter', 'failed')->assertSee('Broken one')->assertDontSee('Fine one');
        $page->set('resultFilter', 'errors')->assertSee('Unreachable')->assertDontSee('Broken one');
    }

    public function test_long_bodies_get_show_all_and_truncation_is_said(): void
    {
        $run = Run::factory()->passed(1)->create();
        $this->card($run, ['response_body' => implode("\n", range(1, 80)), 'response_content_type' => 'text/plain', 'response_body_truncated' => true]);

        Livewire::test(RunShow::class, ['run' => $run])->assertSee('Show all')->assertSee('Cut at 64.0 KB.');
    }

    public function test_ui_review_fixes(): void
    {
        $run = Run::factory()->passed(1)->create();
        // 0 tests: neutral, not a green "Passed".
        $this->card($run, ['item_name' => 'Untested'], []);
        // A body cut at 64 KB is one long line: still capped, with "Show all".
        $this->card($run, ['item_name' => 'Minified', 'response_body' => str_repeat('x', 5000), 'response_content_type' => 'text/plain']);
        // Binary with unknown size: no dangling ", )".
        $this->card($run, ['item_name' => 'Blob', 'response_body' => null, 'response_body_binary' => true, 'response_content_type' => 'image/png', 'response_size' => null]);

        Livewire::test(RunShow::class, ['run' => $run])
            ->assertSee('No tests')
            ->assertSee('Show all (4.9 KB)')
            ->assertSee('Binary body (image/png) — not stored.')
            ->assertSeeHtml('aria-controls="rq')
            ->assertSeeHtml('role="tabpanel"')
            ->assertSeeHtml('data-active="true"')
            ->assertSee('Copy')
            // Accordion: every card starts open, the header folds it, plus Expand/Collapse all.
            ->assertSeeHtml('aria-expanded="true"')
            ->assertSeeHtml('x-on:click="folded = ! folded"')
            ->assertSee('Collapse all')
            ->assertSee('Expand all');
    }

    public function test_many_passing_tests_fold_away_but_failures_stay_open(): void
    {
        $run = Run::factory()->passed(8)->create();
        $this->card($run, [], array_map(fn ($i) => ['assertion' => "check {$i}", 'passed' => true], range(1, 8)));

        Livewire::test(RunShow::class, ['run' => $run])->assertSee('Tests (8, all passed)')->assertSee('Passed:');
    }

    public function test_runs_from_before_payloads_keep_the_old_table(): void
    {
        $run = Run::factory()->passed(1)->create();
        RunResult::factory()->for($run)->create(['assertion' => 'legacy assertion']);

        $this->get(route('runs.show', $run))->assertOk()->assertSee('legacy assertion')->assertSee('<caption class="sr-only">Results in execution order</caption>', false);
    }
}
