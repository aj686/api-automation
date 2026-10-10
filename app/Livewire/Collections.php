<?php

namespace App\Livewire;

use App\Enums\CollectionKind;
use App\Exceptions\ImportException;
use App\Livewire\Concerns\DiscardsUploads;
use App\Models\Collection;
use App\Models\Project;
use App\Services\CollectionImporter;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;

class Collections extends Component
{
    use DiscardsUploads, WithFileUploads;

    private const MAX_UPLOAD_KB = 5120;

    public Project $project;

    // New collection
    /** @var TemporaryUploadedFile|null */
    public $collectionFile = null;

    public string $name = '';

    public string $slug = '';

    public string $kind = 'other';

    // Replace the file of an existing collection
    #[Locked]
    public ?int $replacingId = null;

    /** @var TemporaryUploadedFile|null */
    public $replacementFile = null;

    /** Safety warnings from the last import; counts only, never values. @var list<string> */
    public array $warnings = [];

    public function mount(Project $project): void
    {
        $this->project = $project;
    }

    public function upload(CollectionImporter $importer): void
    {
        $this->warnings = [];

        $this->validate([
            'collectionFile' => ['required', 'file', 'max:'.self::MAX_UPLOAD_KB],
            'name' => ['nullable', 'string', 'max:150'],
            'slug' => ['nullable', 'string', 'max:150', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/'],
            'kind' => ['required', Rule::enum(CollectionKind::class)],
        ], ['slug.regex' => 'The slug may only contain lowercase letters, numbers and single hyphens.'],
            ['collectionFile' => 'file']);

        try {
            $json = $this->collectionFile->get();
            $info = $importer->inspect($json);

            // The slug comes from the typed name, else the collection's own name.
            $name = trim($this->name) ?: $info['name'];
            $slug = trim($this->slug) ?: Str::slug($name);

            if ($slug === '') {
                $this->addError('slug', 'Enter a slug — the name has no letters or numbers to build one from.');

                return;
            }

            if ($this->project->collections()->where('slug', $slug)->exists()) {
                $this->addError('slug', "This project already has a collection with the slug {$slug}. Replace its file instead, or choose another slug.");

                return;
            }

            $collection = $importer->store($this->project, $json, $this->collectionFile->getClientOriginalName(), [
                'name' => $name,
                'slug' => $slug,
                'kind' => $this->kind,
            ]);
        } catch (ImportException $e) {
            $this->addError('collectionFile', $e->getMessage());

            return;
        } finally {
            $this->discardUpload($this->collectionFile);
            $this->collectionFile = null;
        }

        $this->warnings = $info['warnings'];
        $this->reset('name', 'slug', 'kind');
        session()->now('status', 'Imported '.$collection->name.' ('.Str::plural('request', $info['requests'], true).').');
    }

    public function startReplace(int $id): void
    {
        $this->replacingId = $this->collection($id)->id;
        $this->resetValidation();
    }

    public function cancelReplace(): void
    {
        $this->discardUpload($this->replacementFile);
        $this->reset('replacingId', 'replacementFile');
        $this->resetValidation();
    }

    public function replace(CollectionImporter $importer): void
    {
        $this->warnings = [];
        $collection = $this->collection($this->replacingId);

        $this->validate(['replacementFile' => ['required', 'file', 'max:'.self::MAX_UPLOAD_KB]], [], ['replacementFile' => 'file']);

        try {
            $json = $this->replacementFile->get();
            $info = $importer->inspect($json);
            $importer->replace($collection, $json, $this->replacementFile->getClientOriginalName());
        } catch (ImportException $e) {
            $this->addError('replacementFile', $e->getMessage());

            return;
        } finally {
            $this->discardUpload($this->replacementFile);
            $this->replacementFile = null;
        }

        $this->warnings = $info['warnings'];
        $this->reset('replacingId');
        session()->now('status', 'Replaced the file of '.$collection->name.'.');
    }

    public function delete(int $id): void
    {
        $collection = $this->collection($id);
        $collection->delete();

        session()->now('status', 'Deleted collection '.$collection->name.'. Run history was kept.');
    }

    private function collection(?int $id): Collection
    {
        return $this->project->collections()->findOrFail($id);
    }

    public function render(): View
    {
        return view('livewire.collections', [
            'collections' => $this->project->collections()->orderBy('name')->get(),
            'kinds' => CollectionKind::cases(),
        ])->title('Collections · '.$this->project->name);
    }
}
