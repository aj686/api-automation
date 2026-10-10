<?php

namespace Tests\Feature\Ui;

use App\Models\Project;
use App\Models\Run;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class LayoutTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array<string, array{string, string}>
     */
    public static function pages(): array
    {
        return [
            'dashboard' => ['/', 'Dashboard'],
            'projects' => ['/projects', 'Projects'],
            'runs' => ['/runs', 'Runs'],
            'settings' => ['/settings', 'Settings'],
        ];
    }

    #[DataProvider('pages')]
    public function test_every_nav_page_renders_in_the_layout_and_marks_itself_current(string $uri, string $label): void
    {
        $this->get($uri)
            ->assertOk()
            ->assertSee('<title>'.$label.' · API Automation</title>', false)
            ->assertSee('<nav aria-label="Main"', false)
            ->assertSee('href="#main"', false)
            ->assertSee('<main id="main"', false)
            ->assertSee('aria-current="page"', false)
            ->assertSeeInOrder(['aria-current="page"', $label], false);
    }

    public function test_dashboard_shows_an_empty_state_without_projects(): void
    {
        $this->get('/')->assertOk()->assertSee('No projects yet.');
    }

    public function test_dashboard_lists_projects_with_their_latest_run(): void
    {
        $old = Run::factory()->failed()->create(['created_at' => now()->subDay()]);
        Run::factory()->passed(21)->create([
            'project_id' => $old->project_id,
            'project_name' => $old->project_name,
            'duration_ms' => 4800,
        ]);
        Project::factory()->create(['name' => 'Zeta API']);

        $this->get('/')
            ->assertOk()
            ->assertSee($old->project_name)
            ->assertSee('PASS')
            ->assertDontSee('FAIL')
            ->assertSee('21/21')
            ->assertSee('4.8 s')
            ->assertSee('Zeta API')
            ->assertSee('Not run yet');
    }

    public function test_unknown_counts_are_shown_as_a_dash_not_zero(): void
    {
        Run::factory()->create();

        $this->get('/')->assertOk()->assertSee('QUEUED')->assertSee('—')->assertDontSee('0/0');
    }

    public function test_sidebar_lists_projects(): void
    {
        Project::factory()->create(['name' => 'Finance API']);

        $this->get('/runs')->assertOk()->assertSee('<aside aria-label="Projects"', false)->assertSee('Finance API');
    }
}
