<?php

namespace Tests\Feature\Ui;

use App\Enums\EnvironmentType;
use App\Livewire\Environments;
use App\Livewire\EnvironmentShow;
use App\Models\Environment;
use App\Models\EnvironmentVariable;
use App\Models\Project;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Livewire\Features\SupportFileUploads\FileUploadConfiguration;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\TestCase;

class EnvironmentsTest extends TestCase
{
    use RefreshDatabase;

    /** A placeholder that must never appear in any response. */
    private const SECRET = 'YOUR_API_KEY_must_never_render';

    /**
     * Livewire's temporary uploads (the `tmp-for-tests` disk while testing).
     *
     * @return list<string>
     */
    private function temporaryUploads(): array
    {
        return FileUploadConfiguration::storage()->allFiles(FileUploadConfiguration::directory());
    }

    private function showPage(Environment $environment): Testable
    {
        return Livewire::test(EnvironmentShow::class, ['project' => $environment->project, 'environment' => $environment]);
    }

    public function test_creating_an_environment_opens_it(): void
    {
        $project = Project::factory()->create(['slug' => 'demo']);

        Livewire::test(Environments::class, ['project' => $project])
            ->set('name', 'Staging')
            ->set('type', 'staging')
            ->set('base_url_hint', 'https://staging.example.invalid')
            ->call('create')
            ->assertHasNoErrors()
            ->assertRedirect('/projects/demo/environments/staging');

        $environment = Environment::sole();
        $this->assertTrue($environment->allow_automation);
        $this->assertSame(EnvironmentType::Staging, $environment->type);
    }

    public function test_a_new_production_environment_is_excluded_from_automation(): void
    {
        $project = Project::factory()->create();

        Livewire::test(Environments::class, ['project' => $project])
            ->set('name', 'Production')->set('type', 'production')
            ->assertSee('tests may modify real data')
            ->call('create');

        $this->assertFalse(Environment::sole()->allow_automation);
    }

    public function test_create_validates(): void
    {
        $project = Project::factory()->create();
        Environment::factory()->for($project)->create(['slug' => 'staging']);
        Environment::factory()->create(['slug' => 'local']); // other project

        $test = fn () => Livewire::test(Environments::class, ['project' => $project]);
        $test()->set('name', 'Staging')->call('create')->assertHasErrors(['slug' => 'unique']);
        $test()->set('name', 'Local')->call('create')->assertHasNoErrors();
        $test()->set('name', 'X')->set('type', 'banana')->call('create')->assertHasErrors('type');
        $test()->set('name', 'Y')->set('base_url_hint', 'not a url')->call('create')->assertHasErrors('base_url_hint');
    }

    public function test_environment_urls_are_scoped_to_their_project(): void
    {
        $environment = Environment::factory()->create(['slug' => 'staging']);
        $other = Project::factory()->create(['slug' => 'other']);

        $this->get(route('projects.environments.show', [$environment->project, $environment]))->assertOk();
        $this->get('/projects/other/environments/staging')->assertNotFound();
        $this->get(route('projects.environments', $other))->assertOk()->assertSee('No environments yet.');
    }

    public function test_a_secret_value_never_appears_in_the_page_or_its_livewire_state(): void
    {
        $variable = EnvironmentVariable::factory()->secret()->create(['value' => self::SECRET]);
        $environment = $variable->environment;

        $this->get(route('projects.environments.show', [$environment->project, $environment]))
            ->assertOk()
            ->assertSee(EnvironmentVariable::MASK)
            ->assertDontSee(self::SECRET, false);

        // Opening the edit form must not load it either.
        $this->showPage($environment)
            ->call('startEdit', $variable->id)
            ->assertSet('editValue', '')
            ->assertDontSee(self::SECRET, false);
    }

    public function test_non_secret_values_are_shown(): void
    {
        $variable = EnvironmentVariable::factory()->create(['key' => 'base_url', 'value' => 'https://api.example.invalid']);

        $this->showPage($variable->environment)->assertSee('https://api.example.invalid');
    }

    public function test_adding_variables(): void
    {
        $environment = Environment::factory()->create();

        $this->showPage($environment)
            ->set('newKey', 'api_key')->set('newValue', self::SECRET)->set('newSecret', true)
            ->call('addVariable')
            ->assertHasNoErrors()
            ->assertSet('newValue', '')
            ->assertDontSee(self::SECRET, false);

        $stored = $environment->variables()->sole();
        $this->assertTrue($stored->is_secret);
        $this->assertSame(self::SECRET, $stored->value);

        $this->showPage($environment)->set('newKey', 'api_key')->call('addVariable')->assertHasErrors(['newKey' => 'unique']);
        $this->showPage($environment)->set('newKey', 'bad key!')->call('addVariable')->assertHasErrors(['newKey' => 'regex']);
    }

    public function test_editing_a_secret_with_an_empty_value_keeps_it(): void
    {
        $variable = EnvironmentVariable::factory()->secret()->create(['key' => 'api_key', 'value' => self::SECRET]);

        $this->showPage($variable->environment)
            ->call('startEdit', $variable->id)
            ->set('editKey', 'apiKey')
            ->call('saveEdit')
            ->assertHasNoErrors()
            ->assertSet('editingId', null);

        $variable->refresh();
        $this->assertSame('apiKey', $variable->key);
        $this->assertSame(self::SECRET, $variable->value);
    }

    public function test_editing_a_secret_with_a_new_value_replaces_it(): void
    {
        $variable = EnvironmentVariable::factory()->secret()->create(['value' => self::SECRET]);

        $this->showPage($variable->environment)
            ->call('startEdit', $variable->id)
            ->set('editValue', 'YOUR_NEW_API_KEY')
            ->call('saveEdit');

        $this->assertSame('YOUR_NEW_API_KEY', $variable->fresh()->value);
    }

    public function test_unmarking_a_secret_requires_a_new_value(): void
    {
        $variable = EnvironmentVariable::factory()->secret()->create(['value' => self::SECRET]);
        $page = $this->showPage($variable->environment)->call('startEdit', $variable->id)->set('editSecret', false);

        $page->call('saveEdit')->assertHasErrors('editValue');
        $this->assertTrue($variable->fresh()->is_secret);

        $page->set('editValue', 'now-public')->call('saveEdit')->assertHasNoErrors();
        $this->assertFalse($variable->fresh()->is_secret);
        $this->assertSame('now-public', $variable->fresh()->value);
    }

    public function test_variables_from_another_environment_cannot_be_touched(): void
    {
        $mine = Environment::factory()->create();
        $theirs = EnvironmentVariable::factory()->secret()->create();

        $this->showPage($mine)->call('deleteVariable', $theirs->id)->assertNotFound();
        $this->showPage($mine)->call('startEdit', $theirs->id)->assertNotFound();

        $this->assertModelExists($theirs);
    }

    public function test_editing_id_cannot_be_set_from_the_browser(): void
    {
        $variable = EnvironmentVariable::factory()->create();

        $this->expectException(CannotUpdateLockedPropertyException::class);
        $this->showPage($variable->environment)->set('editingId', $variable->id);
    }

    public function test_toggle_and_delete_variables(): void
    {
        $variable = EnvironmentVariable::factory()->create();
        $page = $this->showPage($variable->environment);

        $page->call('toggleEnabled', $variable->id);
        $this->assertFalse($variable->fresh()->enabled);
        $page->assertSee('Disabled');

        $page->call('deleteVariable', $variable->id);
        $this->assertModelMissing($variable);
    }

    public function test_switching_to_production_turns_automation_off(): void
    {
        $environment = Environment::factory()->create(['type' => 'staging']);
        $this->assertTrue($environment->allow_automation);

        $page = $this->showPage($environment)->set('type', 'production')->assertSet('allow_automation', false);
        // Even if the browser re-ticks it in the same request, becoming production wins.
        $page->set('allow_automation', true)->call('saveSettings')->assertHasNoErrors();

        $this->assertTrue($environment->fresh()->isProduction());
        $this->assertFalse($environment->fresh()->allow_automation);

        // A second, deliberate save on an environment that already is production is respected.
        $this->showPage($environment->fresh())->set('allow_automation', true)->call('saveSettings');
        $this->assertTrue($environment->fresh()->allow_automation);
    }

    public function test_delete_needs_the_exact_name_and_removes_variables(): void
    {
        $variable = EnvironmentVariable::factory()->secret()->create();
        $environment = $variable->environment;

        $this->showPage($environment)->set('confirmName', 'nope')->call('delete')->assertHasErrors('confirmName');
        $this->assertModelExists($environment);

        $this->showPage($environment)->set('confirmName', $environment->name)->call('delete')
            ->assertRedirect(route('projects.environments', $environment->project));
        $this->assertModelMissing($environment);
        $this->assertModelMissing($variable);
    }

    public function test_importing_a_postman_file_and_deleting_the_upload(): void
    {
        $environment = Environment::factory()->create();
        $before = $this->temporaryUploads();
        $file = UploadedFile::fake()->createWithContent('staging.postman_environment.json', json_encode([
            'name' => 'Staging',
            'values' => [
                ['key' => 'base_url', 'value' => 'https://api.example.invalid', 'type' => 'default', 'enabled' => true],
                ['key' => 'api_key', 'value' => self::SECRET, 'type' => 'secret', 'enabled' => true],
            ],
            '_postman_variable_scope' => 'environment',
        ]));

        $page = $this->showPage($environment)->set('importFile', $file);
        // Livewire stores the upload plus a <file>.json metadata file beside it.
        $this->assertCount(count($before) + 2, $this->temporaryUploads(), 'the upload is stored first');
        $page->call('import')->assertHasNoErrors();

        $this->assertSame(2, $environment->variables()->count());
        $this->assertTrue($environment->variables()->where('key', 'api_key')->sole()->is_secret);
        $page->assertSee('Imported: 2 added, 0 updated.')->assertSet('importFile', null)->assertDontSee(self::SECRET, false);
        $this->assertSame($before, $this->temporaryUploads(), 'upload deleted');
    }

    public function test_a_rejected_import_also_deletes_the_upload(): void
    {
        $environment = Environment::factory()->create();
        $before = $this->temporaryUploads();

        $this->showPage($environment)
            ->set('importFile', UploadedFile::fake()->createWithContent('x.json', '{not json'))
            ->call('import')
            ->assertHasErrors(['importFile']);

        $this->assertSame(0, $environment->variables()->count());
        $this->assertSame($before, $this->temporaryUploads());
    }
}
