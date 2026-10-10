<?php

namespace Tests\Feature\Ui;

use App\Livewire\Projects;
use App\Livewire\ProjectShow;
use App\Models\Environment;
use App\Models\EnvironmentVariable;
use App\Models\Project;
use App\Models\Run;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class ProjectsTest extends TestCase
{
    use RefreshDatabase;

    public function test_creating_a_project_derives_the_slug_and_opens_it(): void
    {
        Livewire::test(Projects::class)
            ->set('name', '  Finance API ')
            ->set('description', 'Payments and invoices')
            ->call('create')
            ->assertHasNoErrors()
            ->assertRedirect('/projects/finance-api');

        $project = Project::sole();
        $this->assertSame('Finance API', $project->name);
        $this->assertSame('finance-api', $project->slug);
    }

    public function test_a_typed_slug_is_kept(): void
    {
        Livewire::test(Projects::class)
            ->set('name', 'HR API')->set('slug', 'hr')
            ->call('create')
            ->assertRedirect('/projects/hr');
    }

    public function test_create_validates_name_and_slug(): void
    {
        Project::factory()->create(['name' => 'Finance API', 'slug' => 'finance-api']);

        Livewire::test(Projects::class)->set('name', '')->call('create')->assertHasErrors(['name' => 'required']);
        Livewire::test(Projects::class)->set('name', 'Finance API')->call('create')->assertHasErrors(['name' => 'unique']);
        // Different name, same derived slug.
        Livewire::test(Projects::class)->set('name', 'finance api!')->call('create')->assertHasErrors(['slug' => 'unique']);
        Livewire::test(Projects::class)->set('name', 'Other')->set('slug', 'Bad Slug')->call('create')->assertHasErrors(['slug' => 'regex']);
        Livewire::test(Projects::class)->set('name', '!!!')->call('create')->assertHasErrors(['slug' => 'required']);

        $this->assertSame(1, Project::count());
    }

    public function test_projects_page_lists_projects_with_counts_and_latest_run(): void
    {
        $run = Run::factory()->failed()->create();

        $this->get('/projects')
            ->assertOk()
            ->assertSee($run->project_name)
            ->assertSee('href="'.route('projects.show', $run->project).'"', false)
            ->assertSee('FAIL');
    }

    public function test_project_page_uses_the_slug_in_its_url(): void
    {
        $project = Project::factory()->create(['slug' => 'demo-api']);
        Environment::factory()->production()->for($project)->create();

        $this->assertSame(url('/projects/demo-api'), route('projects.show', $project));

        $this->get('/projects/demo-api')
            ->assertOk()
            ->assertSee('<title>'.e($project->name).' · API Automation</title>', false)
            ->assertSee('PRODUCTION')
            ->assertSee('Not run yet')
            ->assertSeeInOrder(['<aside aria-label="Projects"', 'aria-current="page"', e($project->name)], false);

        $this->get('/projects/missing')->assertNotFound();
    }

    public function test_saving_details(): void
    {
        $project = Project::factory()->create(['slug' => 'demo-api']);

        Livewire::test(ProjectShow::class, ['project' => $project])
            ->set('name', 'Demo API v2')
            ->set('description', 'Updated')
            ->call('save')
            ->assertHasNoErrors()
            ->assertNoRedirect()
            ->assertSee('Project saved.');

        $this->assertSame('Demo API v2', $project->fresh()->name);
        $this->assertSame('Updated', $project->fresh()->description);
    }

    public function test_changing_the_slug_redirects_to_the_new_url(): void
    {
        $project = Project::factory()->create(['slug' => 'demo-api']);

        Livewire::test(ProjectShow::class, ['project' => $project])
            ->set('slug', 'demo')
            ->call('save')
            ->assertRedirect('/projects/demo');
    }

    public function test_saving_rejects_another_projects_name_but_allows_its_own(): void
    {
        Project::factory()->create(['name' => 'Taken']);
        $project = Project::factory()->create(['name' => 'Mine']);

        Livewire::test(ProjectShow::class, ['project' => $project])->call('save')->assertHasNoErrors();
        Livewire::test(ProjectShow::class, ['project' => $project])->set('name', 'Taken')->call('save')->assertHasErrors(['name' => 'unique']);
    }

    public function test_delete_needs_the_exact_name(): void
    {
        $project = Project::factory()->create(['name' => 'Demo API']);

        Livewire::test(ProjectShow::class, ['project' => $project])
            ->set('confirmName', 'demo api')
            ->call('delete')
            ->assertHasErrors('confirmName')
            ->assertNoRedirect();

        $this->assertModelExists($project);
    }

    public function test_delete_removes_credentials_but_keeps_run_history(): void
    {
        $run = Run::factory()->passed()->create();
        EnvironmentVariable::factory()->secret()->create(['environment_id' => $run->environment_id]);
        $project = $run->project;

        Livewire::test(ProjectShow::class, ['project' => $project])
            ->set('confirmName', $project->name)
            ->call('delete')
            ->assertRedirect('/projects');

        $this->assertModelMissing($project);
        $this->assertSame(0, EnvironmentVariable::count());
        $this->assertSame($project->name, $run->fresh()->project_name);
        $this->assertSame('Deleted project '.$project->name.'. Its run history was kept.', session('status'));
    }
}
