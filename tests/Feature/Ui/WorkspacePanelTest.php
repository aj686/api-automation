<?php

namespace Tests\Feature\Ui;

use App\Livewire\CollectionShow;
use App\Livewire\EnvironmentSwitcher;
use App\Livewire\WorkspacePanel;
use App\Models\Collection;
use App\Models\Environment;
use App\Models\Project;
use App\Models\Run;
use App\Services\CollectionImporter;
use App\Support\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The workspace shell (decisions.md D-040): side panel, environment switcher.
 */
class WorkspacePanelTest extends TestCase
{
    use RefreshDatabase;

    private Project $project;

    private Collection $collection;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake(CollectionImporter::DISK);
        Queue::fake();

        $this->project = Project::factory()->create(['slug' => 'demo', 'name' => 'Demo']);
        $this->collection = app(CollectionImporter::class)->store($this->project, json_encode([
            'info' => ['name' => 'Sample API', 'schema' => 'https://schema.getpostman.com/json/collection/v2.1.0/collection.json'],
            'item' => [
                ['id' => 'login', 'name' => 'Login and obtain JWT', 'request' => ['method' => 'POST', 'url' => '{{base_url}}/api/login']],
                ['id' => 'folder', 'name' => 'Projects', 'item' => [
                    ['id' => 'info', 'name' => 'GetProjectInfo', 'request' => ['method' => 'POST', 'url' => '{{base_url}}/api/project']],
                ]],
            ],
        ]), 'sample-api.json', ['slug' => 'sample-api']);
    }

    private function panel()
    {
        return Livewire::test(WorkspacePanel::class, ['project' => $this->project, 'currentCollectionId' => $this->collection->id]);
    }

    private function stored(): object
    {
        return json_decode(Storage::disk('local')->get($this->collection->fresh()->stored_path));
    }

    public function test_every_project_page_shows_the_tree_environments_and_history(): void
    {
        Environment::factory()->for($this->project)->create(['name' => 'Staging', 'type' => 'staging']);
        Run::factory()->passed()->create(['project_id' => $this->project->id, 'collection_name' => 'Sample API']);

        $this->get('/projects/demo/environments')
            ->assertOk()
            ->assertSee('Login and obtain JWT')
            ->assertSee('GetProjectInfo')
            ->assertSee('href="'.route('projects.collections.show', [$this->project, $this->collection]).'?request=info"', false)
            ->assertSee('Staging')
            ->assertSee('Capture URLs');
    }

    public function test_tree_edits_are_written_and_announced_to_the_open_page(): void
    {
        $panel = $this->panel();

        $panel->call('addFolder', $this->collection->id)->assertSet('renameValue', 'New folder')
            ->set('renameValue', 'Orders')->call('saveRename')
            ->assertDispatched('collection-changed', collection: $this->collection->id);
        $this->assertSame(['Login and obtain JWT', 'Projects', 'Orders'], array_column($this->stored()->item, 'name'));

        $panel->call('addRequest', $this->collection->id, 'folder')->assertDispatched('open-request');
        $this->assertSame(['GetProjectInfo', 'New request'], array_column($this->stored()->item[1]->item, 'name'));

        $panel->call('moveItem', $this->collection->id, 'login', 1);
        $this->assertSame(['Projects', 'Login and obtain JWT', 'Orders'], array_column($this->stored()->item, 'name'));

        $panel->call('duplicateItem', $this->collection->id, 'login');
        $this->assertSame('Login and obtain JWT copy', $this->stored()->item[2]->name);

        $panel->call('deleteItem', $this->collection->id, 'folder');
        $this->assertSame(['Login and obtain JWT', 'Login and obtain JWT copy', 'Orders'], array_column($this->stored()->item, 'name'));
    }

    public function test_adding_a_request_to_another_collection_opens_its_page(): void
    {
        Livewire::test(WorkspacePanel::class, ['project' => $this->project])
            ->call('addRequest', $this->collection->id)
            ->assertRedirectContains('/projects/demo/collections/sample-api?request=');
    }

    public function test_the_open_page_follows_tree_edits_and_keeps_unsaved_form_edits(): void
    {
        $page = Livewire::test(CollectionShow::class, ['project' => $this->project, 'collection' => $this->collection])
            ->assertSet('selectedId', 'login')
            ->set('form.method', 'PUT');

        // The panel renames the open request while its form has unsaved edits.
        $this->panel()->call('startRename', $this->collection->id, 'login')->set('renameValue', 'Sign in')->call('saveRename');
        $page->dispatch('collection-changed', collection: $this->collection->id, renamed: 'login', name: 'Sign in')
            ->assertSet('form.name', 'Sign in')
            ->assertSet('form.method', 'PUT')
            ->assertSet('unsaved', true)
            ->call('saveRequest')->assertHasNoErrors();
        $this->assertSame(['Sign in', 'PUT'], [$this->stored()->item[0]->name, $this->stored()->item[0]->request->method]);

        // The panel deletes it.
        $this->panel()->call('deleteItem', $this->collection->id, 'login');
        $page->dispatch('collection-changed', collection: $this->collection->id)->assertSet('selectedId', null)->assertSet('pane', 'overview');
    }

    public function test_request_in_the_url_opens_that_request(): void
    {
        Livewire::withQueryParams(['request' => 'info'])
            ->test(CollectionShow::class, ['project' => $this->project, 'collection' => $this->collection])
            ->assertSet('selectedId', 'info')
            ->assertSet('form.name', 'GetProjectInfo');

        Livewire::withQueryParams(['request' => 'folder'])
            ->test(CollectionShow::class, ['project' => $this->project, 'collection' => $this->collection])
            ->assertSet('selectedId', 'login', 'a folder id falls back to the first request');
    }

    public function test_one_active_environment_per_workspace_never_production_by_default(): void
    {
        $production = Environment::factory()->for($this->project)->create(['name' => 'A Prod', 'type' => 'production']);
        $staging = Environment::factory()->for($this->project)->create(['name' => 'Staging', 'type' => 'staging']);

        $this->assertSame($staging->id, Workspace::activeEnvironment($this->project)->id);

        Livewire::test(EnvironmentSwitcher::class, ['project' => $this->project])
            ->assertSet('environmentId', $staging->id)
            ->set('environmentId', $production->id)
            ->assertDispatched('environment-changed', environment: $production->id);
        $this->assertSame($production->id, Workspace::activeEnvironment($this->project)->id);

        $this->panel()->call('chooseEnvironment', $staging->id)->assertDispatched('environment-chosen', environment: $staging->id);
        $this->assertSame($staging->id, Workspace::activeEnvironment($this->project)->id);

        // Another project's environment is never accepted.
        $other = Environment::factory()->create();
        Livewire::test(EnvironmentSwitcher::class, ['project' => $this->project])->set('environmentId', $other->id)->assertSet('environmentId', $staging->id);

        Livewire::test(CollectionShow::class, ['project' => $this->project, 'collection' => $this->collection])->assertSet('environmentId', $staging->id);
    }

    public function test_deleted_things_close_their_tabs(): void
    {
        $this->panel()->call('deleteItem', $this->collection->id, 'folder')
            ->assertDispatched('tab-gone', keys: ['req:'.$this->collection->id.':info']);

        Livewire::withQueryParams(['request' => 'info'])
            ->test(CollectionShow::class, ['project' => $this->project, 'collection' => $this->collection])
            ->assertDispatched('tab-gone', keys: ['req:'.$this->collection->id.':info']);

        Livewire::test(\App\Livewire\Collections::class, ['project' => $this->project])
            ->call('delete', $this->collection->id)
            ->assertDispatched('tab-gone', prefixes: ['req:'.$this->collection->id.':', 'col:'.$this->collection->id, 'runner:'.$this->collection->id]);
    }
}
