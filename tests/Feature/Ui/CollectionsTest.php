<?php

namespace Tests\Feature\Ui;

use App\Livewire\Collections;
use App\Models\Collection;
use App\Models\Project;
use App\Models\Run;
use App\Services\CollectionImporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Features\SupportFileUploads\FileUploadConfiguration;
use Livewire\Livewire;
use Tests\TestCase;
use Tests\Unit\Services\CollectionImporterTest;

class CollectionsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake(CollectionImporter::DISK);
    }

    private function upload(string $json, string $name = 'demo.postman_collection.json'): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, $json);
    }

    /**
     * @return list<string>
     */
    private function temporaryUploads(): array
    {
        return FileUploadConfiguration::storage()->allFiles(FileUploadConfiguration::directory());
    }

    public function test_importing_a_collection(): void
    {
        $project = Project::factory()->create();
        $before = $this->temporaryUploads();

        Livewire::test(Collections::class, ['project' => $project])
            ->set('collectionFile', $this->upload(CollectionImporterTest::collection()))
            ->set('kind', 'smoke')
            ->call('upload')
            ->assertHasNoErrors()
            ->assertSee('Imported Demo Smoke (2 requests).')
            ->assertSet('collectionFile', null)
            ->assertSet('warnings', []);

        $collection = $project->collections()->sole();
        $this->assertSame('demo-smoke', $collection->slug);
        $this->assertSame('smoke', $collection->kind->value);
        $this->assertTrue(Storage::disk('local')->exists($collection->stored_path));
        $this->assertSame($before, $this->temporaryUploads(), 'upload and its metadata deleted');
    }

    public function test_typed_name_and_slug_win(): void
    {
        $project = Project::factory()->create();

        Livewire::test(Collections::class, ['project' => $project])
            ->set('collectionFile', $this->upload(CollectionImporterTest::collection()))
            ->set('name', 'Nightly Regression')->set('slug', 'nightly')
            ->call('upload')->assertHasNoErrors();

        $this->assertSame(['Nightly Regression', 'nightly'], array_values($project->collections()->sole()->only('name', 'slug')));
    }

    public function test_a_duplicate_slug_is_refused_and_nothing_is_stored(): void
    {
        $project = Project::factory()->create();
        Collection::factory()->for($project)->create(['slug' => 'demo-smoke']);

        Livewire::test(Collections::class, ['project' => $project])
            ->set('collectionFile', $this->upload(CollectionImporterTest::collection()))
            ->call('upload')
            ->assertHasErrors('slug');

        $this->assertSame(1, $project->collections()->count());
        $this->assertSame([], Storage::disk('local')->allFiles('collections'));
    }

    public function test_an_invalid_file_shows_why_and_deletes_the_upload(): void
    {
        $project = Project::factory()->create();
        $before = $this->temporaryUploads();

        Livewire::test(Collections::class, ['project' => $project])
            ->set('collectionFile', $this->upload('{"name":"env","values":[]}', 'env.json'))
            ->call('upload')
            ->assertHasErrors('collectionFile')
            ->assertSee('environment, not a collection');

        $this->assertSame(0, Collection::count());
        $this->assertSame($before, $this->temporaryUploads());
    }

    public function test_warnings_are_shown_after_import(): void
    {
        $project = Project::factory()->create();

        Livewire::test(Collections::class, ['project' => $project])
            ->set('collectionFile', $this->upload(CollectionImporterTest::collection([
                ['name' => 'a', 'request' => ['url' => 'http://localhost/a']],
            ])))
            ->call('upload')
            ->assertHasNoErrors()
            ->assertSee('1 request call localhost');
    }

    public function test_replacing_a_file(): void
    {
        $project = Project::factory()->create();
        $collection = (new CollectionImporter)->store($project, CollectionImporterTest::collection(), 'old.json');

        Livewire::test(Collections::class, ['project' => $project])
            ->call('startReplace', $collection->id)
            ->set('replacementFile', $this->upload(CollectionImporterTest::collection(), 'new.json'))
            ->call('replace')
            ->assertHasNoErrors()
            ->assertSet('replacingId', null)
            ->assertSee('Replaced the file of Demo Smoke.');

        $this->assertSame('new.json', $collection->fresh()->original_filename);
    }

    public function test_deleting_keeps_runs_and_removes_the_file(): void
    {
        $project = Project::factory()->create();
        $collection = (new CollectionImporter)->store($project, CollectionImporterTest::collection(), 'a.json');
        $run = Run::factory()->create(['project_id' => $project->id, 'collection_id' => $collection->id, 'collection_name' => $collection->name]);

        Livewire::test(Collections::class, ['project' => $project])
            ->call('delete', $collection->id)
            ->assertSee('Deleted collection Demo Smoke. Run history was kept.');

        $this->assertModelMissing($collection);
        $this->assertFalse(Storage::disk('local')->exists($collection->stored_path));
        $this->assertSame('Demo Smoke', $run->fresh()->collection_name);
    }

    public function test_collections_of_another_project_cannot_be_touched(): void
    {
        $mine = Project::factory()->create();
        $theirs = Collection::factory()->create();

        Livewire::test(Collections::class, ['project' => $mine])->call('delete', $theirs->id)->assertNotFound();
        $this->assertModelExists($theirs);
    }

    public function test_the_page_and_tab(): void
    {
        $project = Project::factory()->create(['slug' => 'demo']);
        Collection::factory()->for($project)->create(['name' => 'Demo Smoke']);

        $this->get('/projects/demo/collections')
            ->assertOk()
            ->assertSee('Demo Smoke')
            ->assertSee('href="'.route('projects.collections', $project).'"', false)
            ->assertSeeInOrder(['aria-current="page"', 'Collections'], false);
    }
}
