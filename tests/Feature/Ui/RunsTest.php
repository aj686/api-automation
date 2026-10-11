<?php

namespace Tests\Feature\Ui;

use App\Enums\RunStatus;
use App\Enums\RunTrigger;
use App\Livewire\Runs;
use App\Livewire\RunShow;
use App\Models\Environment;
use App\Models\Project;
use App\Models\Run;
use App\Models\RunResult;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class RunsTest extends TestCase
{
    use RefreshDatabase;

    public function test_runs_page_lists_runs_newest_first_with_links_to_details(): void
    {
        $old = Run::factory()->passed()->create(['collection_name' => 'Old Smoke', 'created_at' => now()->subHour()]);
        $new = Run::factory()->failed()->create(['collection_name' => 'New Smoke']);

        $this->get('/runs')
            ->assertOk()
            ->assertSee('<title>Runs · API Automation</title>', false)
            ->assertSeeInOrder(['New Smoke', 'Old Smoke'])
            ->assertSee('href="'.route('runs.show', $new).'"', false)
            ->assertSee($old->project_name)
            ->assertSee('2/3');
    }

    public function test_unknown_counts_show_a_dash_not_zero(): void
    {
        Run::factory()->create(['status' => RunStatus::Error, 'error_code' => 'report_missing']);

        $html = Livewire::test(Runs::class)->html();
        // Passed and Duration are both unknown.
        $this->assertSame(2, preg_match_all('/<td class="px-3 py-2 tabular-nums">\s*—\s*<\/td>/', $html));
        $this->assertDoesNotMatchRegularExpression('/>\s*0\/0\s*</', $html);
    }

    public function test_status_filter_groups_statuses(): void
    {
        Run::factory()->passed()->create(['collection_name' => 'Passing']);
        Run::factory()->failed()->create(['collection_name' => 'Failing']);
        Run::factory()->create(['collection_name' => 'Erroring', 'status' => RunStatus::Error]);
        Run::factory()->create(['collection_name' => 'Too Slow', 'status' => RunStatus::Timeout]);
        Run::factory()->create(['collection_name' => 'Waiting', 'status' => RunStatus::Queued]);

        Livewire::test(Runs::class)->set('status', 'errors')
            ->assertSee(['Erroring', 'Too Slow'])->assertDontSee(['Passing', 'Failing', 'Waiting']);
        Livewire::test(Runs::class)->set('status', 'failed')
            ->assertSee('Failing')->assertDontSee(['Passing', 'Erroring']);
        Livewire::test(Runs::class)->set('status', 'active')
            ->assertSee('Waiting')->assertDontSee(['Passing', 'Failing']);

        // From the query string, as a bookmarked link would arrive.
        $this->get('/runs?status=passed')->assertSee('Passing')->assertDontSee('Failing');
        // An unknown value is ignored, not an error.
        $this->get('/runs?status=nonsense')->assertOk()->assertSee(['Passing', 'Failing']);
    }

    public function test_project_filter_and_project_runs_tab(): void
    {
        $finance = Project::factory()->create(['name' => 'Finance API', 'slug' => 'finance-api']);
        $hr = Project::factory()->create(['name' => 'HR API', 'slug' => 'hr-api']);
        Run::factory()->passed()->create(['environment_id' => Environment::factory()->for($finance), 'collection_name' => 'Finance Smoke']);
        Run::factory()->passed()->create(['environment_id' => Environment::factory()->for($hr), 'collection_name' => 'HR Smoke']);

        $this->get('/runs?project=finance-api')->assertSee('Finance Smoke')->assertDontSee('HR Smoke');
        // A slug that does not exist matches nothing rather than everything.
        $this->get('/runs?project=missing')->assertOk()->assertSee('No runs match this filter.')->assertDontSee('Finance Smoke');

        $this->get('/projects/hr-api/runs')
            ->assertOk()
            ->assertSee('<title>Runs · HR API · API Automation</title>', false)
            ->assertSee('HR Smoke')->assertDontSee('Finance Smoke');
        $this->assertMatchesRegularExpression('/aria-current="page"[^>]*>Runs<\/a>/', $this->get('/projects/hr-api/runs')->getContent());

        $this->get('/projects/missing/runs')->assertNotFound();
    }

    public function test_runs_of_a_deleted_project_stay_in_history(): void
    {
        $run = Run::factory()->passed()->create();
        $name = $run->project_name;
        $run->project->delete();

        $this->get('/runs')->assertOk()->assertSee($name);
        $this->get(route('runs.show', $run))->assertOk()->assertSee($name.' <span class="text-xs text-zinc-500">(deleted)</span>', false);
    }

    public function test_list_polls_only_while_a_run_on_the_page_is_active(): void
    {
        Run::factory()->passed()->create();
        // The layout's runner badge polls on its own; only the 2 s poll belongs to this page.
        $this->get('/runs')->assertDontSee('wire:poll.2s', false);

        Run::factory()->create(['status' => RunStatus::Running]);
        $this->get('/runs')->assertSee('wire:poll.2s', false);
    }

    public function test_list_is_paginated(): void
    {
        Run::factory()->count(Runs::PER_PAGE + 1)->passed()->create();

        $page = $this->get('/runs')->assertOk();
        $this->assertSame(Runs::PER_PAGE, substr_count($page->getContent(), '<tr wire:key="run-'));
        $this->assertSame(1, substr_count($this->get('/runs?page=2')->getContent(), '<tr wire:key="run-'));
    }

    public function test_run_detail_shows_summary_results_and_redacted_log(): void
    {
        $run = Run::factory()->failed(assertions: 2, failures: 1)->create([
            'trigger' => RunTrigger::Api,
            'log' => "→ GET Login\n  ?api_key=[REDACTED]",
        ]);
        RunResult::factory()->for($run)->create(['position' => 0, 'item_name' => 'Login', 'assertion' => 'Status code is 200']);
        RunResult::factory()->for($run)->failed()->create(['position' => 1, 'item_name' => 'List orders', 'assertion' => 'Has data']);

        $this->get(route('runs.show', $run))
            ->assertOk()
            ->assertSee('FAIL')
            ->assertSeeInOrder(['Login', 'List orders'])
            ->assertSee('expected response to have status code 200 but got 500')
            ->assertSee('Execution log')
            ->assertSee('?api_key=[REDACTED]')
            ->assertSee('API')
            ->assertSee('1.71.0');

        $this->get('/runs/01JUNKNOWNRUNID00000000000')->assertNotFound();
    }

    public function test_result_filter_separates_failed_assertions_from_errors(): void
    {
        $run = Run::factory()->failed()->create();
        RunResult::factory()->for($run)->create(['position' => 0, 'assertion' => 'Ok check']);
        RunResult::factory()->for($run)->failed()->create(['position' => 1, 'assertion' => 'Wrong value']);
        // No response at all: the API was never reached.
        RunResult::factory()->for($run)->create([
            'position' => 2, 'assertion' => null, 'passed' => false, 'response_code' => null,
            'response_time_ms' => null, 'error_message' => 'getaddrinfo ENOTFOUND api.example.invalid',
        ]);

        Livewire::test(RunShow::class, ['run' => $run])
            ->assertViewHas('counts', ['all' => 3, 'passed' => 1, 'failed' => 1, 'errors' => 1])
            ->set('resultFilter', 'failed')->assertSee('Wrong value')->assertDontSee(['Ok check', 'ENOTFOUND'])
            ->set('resultFilter', 'errors')->assertSee('ENOTFOUND')->assertDontSee(['Ok check', 'Wrong value'])
            ->set('resultFilter', 'passed')->assertSee('Ok check')->assertDontSee(['Wrong value', 'ENOTFOUND']);

        $this->get(route('runs.show', $run).'?show=errors')->assertSee('ENOTFOUND')->assertDontSee('Wrong value');
    }

    public function test_error_run_without_results_explains_itself(): void
    {
        $run = Run::factory()->create([
            'status' => RunStatus::Error,
            'error_code' => 'report_missing',
            'error_message' => 'Postman CLI wrote no report.',
            'finished_at' => now(),
        ]);

        $this->get(route('runs.show', $run))
            ->assertOk()
            ->assertSee('Postman CLI wrote no report.')
            ->assertSee('report_missing')
            ->assertSee('No results were recorded')
            ->assertDontSee('Execution log');
    }

    public function test_cancel_from_detail_requests_a_stop_and_polls_while_active(): void
    {
        $run = Run::factory()->create(['status' => RunStatus::Running]);

        Livewire::test(RunShow::class, ['run' => $run])
            ->assertSeeHtml('wire:poll.2s')
            ->call('cancelRun')
            ->assertSee('Stopping…');

        $this->assertNotNull($run->fresh()->cancel_requested_at);
        // Status changes only once the worker confirms the process is gone.
        $this->assertSame(RunStatus::Running, $run->fresh()->status);

        $finished = Run::factory()->passed()->create();
        Livewire::test(RunShow::class, ['run' => $finished])
            ->assertDontSeeHtml('wire:poll')
            ->assertDontSee('Cancel run');
    }

    public function test_project_page_and_dashboard_link_to_run_details(): void
    {
        $run = Run::factory()->passed()->create();
        $link = 'href="'.route('runs.show', $run).'"';

        $this->get(route('projects.show', $run->project))
            ->assertSee($link, false)
            ->assertSee('href="'.route('projects.runs', $run->project).'"', false)
            ->assertDontSee('phase 11');
        $this->get('/')->assertSee($link, false);
    }
}
