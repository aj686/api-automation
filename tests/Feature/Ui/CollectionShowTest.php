<?php

namespace Tests\Feature\Ui;

use App\Enums\RunStatus;
use App\Enums\RunTrigger;
use App\Livewire\CollectionShow;
use App\Models\Collection;
use App\Models\Environment;
use App\Models\Project;
use App\Models\Run;
use App\Services\CollectionImporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The collection workbench (decisions.md D-038).
 */
class CollectionShowTest extends TestCase
{
    use RefreshDatabase;

    private Project $project;

    private Collection $collection;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake(CollectionImporter::DISK);
        Queue::fake();

        $this->project = Project::factory()->create(['slug' => 'demo']);
        Environment::factory()->for($this->project)->create(['name' => 'Staging', 'type' => 'staging']);
        $this->collection = app(CollectionImporter::class)->store($this->project, json_encode([
            'info' => ['name' => 'MyPantau', 'schema' => 'https://schema.getpostman.com/json/collection/v2.1.0/collection.json'],
            'item' => [
                ['id' => 'login', 'name' => 'Login and obtain JWT', 'request' => [
                    'method' => 'POST',
                    'header' => [['key' => 'Accept', 'value' => 'application/json']],
                    'body' => ['mode' => 'raw', 'raw' => '{"email":"{{email}}","password":"{{password}}"}', 'options' => ['raw' => ['language' => 'json']]],
                    'url' => ['raw' => '{{base_url}}/api/login', 'host' => ['{{base_url}}'], 'path' => ['api', 'login']],
                ], 'event' => [['listen' => 'test', 'script' => ['exec' => ['pm.environment.set("token", pm.response.json().token);']]]]],
                ['id' => 'folder', 'name' => 'Projects', 'item' => [
                    ['id' => 'info', 'name' => 'GetProjectInfo', 'request' => ['method' => 'POST', 'url' => '{{base_url}}/api/project',
                        'auth' => ['type' => 'bearer', 'bearer' => [['key' => 'token', 'value' => '{{token}}']]]]],
                ]],
            ],
        ]), 'mypantau.json', ['slug' => 'mypantau']);
    }

    private function workbench()
    {
        return Livewire::test(CollectionShow::class, ['project' => $this->project, 'collection' => $this->collection]);
    }

    private function stored(): object
    {
        return json_decode(Storage::disk('local')->get($this->collection->fresh()->stored_path));
    }

    public function test_the_page_shows_the_tree_and_opens_the_first_request(): void
    {
        $this->get('/projects/demo/collections/mypantau')
            ->assertOk()
            ->assertSee('Login and obtain JWT')
            ->assertSee('GetProjectInfo')
            ->assertSee('Projects')
            ->assertSee('Staging');

        // wire:model fields get their values from Livewire's JS, not the HTML.
        $this->workbench()
            ->assertSet('form.url', '{{base_url}}/api/login')
            ->assertSet('form.test', 'pm.environment.set("token", pm.response.json().token);')
            ->assertSet('form.body.language', 'json');

        $this->get('/projects/other/collections/mypantau')->assertNotFound();
    }

    public function test_editing_and_saving_a_request_writes_the_collection(): void
    {
        $component = $this->workbench()
            ->assertSet('selectedId', 'login')
            ->set('form.method', 'PUT')
            ->set('form.url', '{{base_url}}/api/v2/login?remember=1')
            ->assertSet('unsaved', true)
            ->assertSet('form.params', [['key' => 'remember', 'value' => '1', 'enabled' => true, 'description' => '']])
            ->call('addRow', 'headers')
            ->set('form.headers.1.key', 'X-Trace')
            ->set('form.headers.1.value', '{{run_id}}')
            ->set('form.params.0.enabled', false)
            ->assertSet('form.url', '{{base_url}}/api/v2/login')
            ->call('saveRequest')
            ->assertHasNoErrors()
            ->assertSet('unsaved', false);

        $login = $this->stored()->item[0];
        $this->assertSame('PUT', $login->request->method);
        $this->assertSame(['api', 'v2', 'login'], $login->request->url->path);
        $this->assertTrue($login->request->url->query[0]->disabled);
        $this->assertSame('X-Trace', $login->request->header[1]->key);
        $this->assertSame($this->collection->fresh()->sha256, $component->get('baseSha'));
    }

    public function test_save_is_refused_when_the_file_changed_elsewhere(): void
    {
        $component = $this->workbench()->set('form.name', 'Mine');

        // Another tab saves first.
        $this->workbench()->set('form.name', 'Theirs')->call('saveRequest');

        $component->call('saveRequest')->assertHasErrors('collection');
        $this->assertSame('Theirs', $this->stored()->item[0]->name);
    }

    public function test_tree_edits_are_written_at_once_and_keep_unsaved_form_edits_marked(): void
    {
        $component = $this->workbench()->set('form.name', 'Not saved yet');

        $component->call('addFolder')->assertSet('renameValue', 'New folder')->set('renameValue', 'Orders')->call('saveRename');
        $component->call('addRequest', 'folder')->assertSet('form.name', 'New request');
        $this->assertSame(['Login and obtain JWT', 'Projects', 'Orders'], array_column($this->stored()->item, 'name'), 'the unsaved name was not written');
        $this->assertSame(['GetProjectInfo', 'New request'], array_column($this->stored()->item[1]->item, 'name'));

        $component->call('moveItem', 'login', 1);
        $this->assertSame(['Projects', 'Login and obtain JWT', 'Orders'], array_column($this->stored()->item, 'name'));

        $component->call('duplicateItem', 'login');
        $this->assertSame('Login and obtain JWT copy', $this->stored()->item[2]->name);

        $component->call('deleteItem', 'folder');
        $this->assertSame(['Login and obtain JWT', 'Login and obtain JWT copy', 'Orders'], array_column($this->stored()->item, 'name'));
        $component->assertSet('selectedId', null)->assertSet('pane', 'overview');
    }

    public function test_send_saves_and_queues_a_single_request_run(): void
    {
        $this->workbench()
            ->set('form.name', 'Login')
            ->call('sendRequest')
            ->assertHasNoErrors();

        $run = Run::sole();
        $this->assertSame(RunTrigger::Send, $run->trigger);
        $this->assertSame(['login'], $run->runOptions()->itemIds());
        $this->assertSame('Login', $this->stored()->item[0]->name, 'saved before sending');
    }

    public function test_the_run_sequence_starts_ticked_in_order_and_runs_what_was_chosen(): void
    {
        $component = $this->workbench()->call('showPane', 'runner');
        $this->assertSame(['login', 'info'], array_column($component->get('sequence'), 'id'));
        $this->assertSame([true, true], array_column($component->get('sequence'), 'checked'));

        // Everything, in order: a plain full run.
        $component->call('runSequence')->assertRedirect();
        $this->assertNull(Run::sole()->options);
        Run::query()->update(['status' => RunStatus::Pass]);

        $component = $this->workbench()
            ->call('sortSequence', 'info', 0)
            ->set('bail', true)
            ->call('runSequence');
        $this->assertSame(['info', 'login'], Run::latest('id')->first()->runOptions()->itemIds());
        $this->assertTrue(Run::latest('id')->first()->runOptions()->bail);
        Run::query()->update(['status' => RunStatus::Pass]);

        $this->workbench()->call('moveInSequence', 0, 1)->set('sequence.0.checked', false)->call('runSequence');
        $this->assertSame(['login'], Run::latest('id')->first()->runOptions()->itemIds());

        $this->workbench()->call('tickAll', false)->call('runSequence')->assertHasErrors('sequence');
    }

    public function test_production_needs_the_typed_name_for_send_and_run(): void
    {
        $production = Environment::factory()->for($this->project)->create(['name' => 'Prod', 'type' => 'production']);

        $component = $this->workbench()->set('environmentId', $production->id);
        $component->call('sendRequest')->assertHasErrors('confirmProduction');
        $component->call('runSequence')->assertHasErrors('confirmProduction');
        $this->assertSame(0, Run::count());

        $component->set('confirmProduction', 'Prod')->call('sendRequest')->assertHasNoErrors();
        $this->assertSame(1, Run::count());
    }

    public function test_download_returns_the_stored_file(): void
    {
        $this->get('/projects/demo/collections/mypantau/download')
            ->assertOk()
            ->assertDownload('mypantau.postman_collection.json');
    }

    public function test_run_pages_show_the_sequence_and_the_runs_list_hides_sends_unless_asked(): void
    {
        $environment = $this->project->environments()->sole();
        $base = ['project_id' => $this->project->id, 'environment_id' => $environment->id, 'collection_id' => $this->collection->id, 'status' => RunStatus::Pass];
        $chosen = Run::factory()->create($base + ['collection_name' => 'Chosen run', 'options' => ['items' => [['id' => 'info', 'name' => 'GetProjectInfo'], ['id' => 'login', 'name' => 'Login and obtain JWT']], 'bail' => true]]);
        Run::factory()->create($base + ['collection_name' => 'Sent one', 'trigger' => RunTrigger::Send, 'options' => ['items' => [['id' => 'login', 'name' => 'Login and obtain JWT']], 'bail' => false]]);

        $this->get(route('runs.show', $chosen))->assertOk()->assertSee('Run sequence:')->assertSee('GetProjectInfo → Login and obtain JWT · stop on error');

        $this->get('/runs')->assertSee('Chosen run')->assertDontSee('Sent one');
        $this->get('/runs?sends=1')->assertSee('Sent one');
        $this->get('/projects/demo')->assertSee('Choose requests and order');
    }
}
