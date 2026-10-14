<?php

namespace App\Livewire;

use App\Exceptions\ImportException;
use App\Models\Collection;
use App\Models\Project;
use App\Services\CollectionImporter;
use App\Services\Postman\CollectionDocument;
use App\Support\Workspace;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Cache;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * The side panel next to the icon rail, Postman/Requestly style (D-040):
 * every collection's tree with its edit actions, the environments with the
 * active one ticked, recent runs and capture URLs — on every project page, so
 * nothing needs opening first. Which section shows is chosen in the browser
 * (rail buttons, Alpine); all four render.
 *
 * Tree edits are written at once (as in Postman) and announced with
 * `collection-changed`, which the open collection page listens to.
 */
class WorkspacePanel extends Component
{
    private const NAME_MAX = 250;

    #[Locked]
    public Project $project;

    /** The collection open in the main area, if any. */
    #[Locked]
    public ?int $currentCollectionId = null;

    #[Locked]
    public ?string $renamingId = null;

    #[Locked]
    public ?int $renamingCollectionId = null;

    public string $renameValue = '';

    public function mount(Project $project, ?int $currentCollectionId = null): void
    {
        $this->project = $project;
        $this->currentCollectionId = $currentCollectionId;
    }

    // --- Environments ---

    public function chooseEnvironment(int $environment): void
    {
        if (Workspace::setActiveEnvironment($this->project, $environment)) {
            $this->dispatch('environment-chosen', environment: $environment)->to(EnvironmentSwitcher::class);
        }
    }

    #[On('environment-changed')]
    public function environmentChanged(): void
    {
        // Re-render: the tick follows the top bar.
    }

    // --- Tree edits ---

    public function addRequest(CollectionImporter $importer, int $collection, ?string $parentId = null): void
    {
        $id = null;
        $model = $this->write($importer, $collection, function (CollectionDocument $document) use ($parentId, &$id) {
            $id = $document->addRequest($parentId, 'New request');
        }, 'Added a request.');

        if ($model) {
            $this->openRequest($model, $id);
        }
    }

    public function addFolder(CollectionImporter $importer, int $collection, ?string $parentId = null): void
    {
        $id = null;
        if ($this->write($importer, $collection, function (CollectionDocument $document) use ($parentId, &$id) {
            $id = $document->addFolder($parentId, 'New folder');
        }, 'Added a folder.')) {
            $this->startRename($collection, $id);
        }
    }

    public function startRename(int $collection, string $id): void
    {
        $row = collect($this->tree($this->collectionModel($collection)))->firstWhere('id', $id);
        if ($row) {
            $this->renamingCollectionId = $collection;
            $this->renamingId = $id;
            $this->renameValue = $row['name'];
        }
    }

    public function cancelRename(): void
    {
        $this->reset('renamingId', 'renamingCollectionId', 'renameValue');
        $this->resetValidation('renameValue');
    }

    public function saveRename(CollectionImporter $importer): void
    {
        $this->validate(['renameValue' => ['required', 'string', 'max:'.self::NAME_MAX]], [], ['renameValue' => 'name']);
        [$collection, $id, $name] = [$this->renamingCollectionId, $this->renamingId, trim($this->renameValue)];

        if ($collection !== null && $id !== null
            && $this->write($importer, $collection, fn (CollectionDocument $document) => $document->rename($id, $name), 'Renamed to '.$name.'.', ['renamed' => $id, 'name' => $name])) {
            $this->cancelRename();
        }
    }

    public function duplicateItem(CollectionImporter $importer, int $collection, string $id): void
    {
        $this->write($importer, $collection, fn (CollectionDocument $document) => $document->duplicate($id), 'Duplicated.');
    }

    public function deleteItem(CollectionImporter $importer, int $collection, string $id): void
    {
        $this->write($importer, $collection, fn (CollectionDocument $document) => $document->delete($id), 'Deleted.');
    }

    public function moveItem(CollectionImporter $importer, int $collection, string $id, int $direction): void
    {
        $this->write($importer, $collection, fn (CollectionDocument $document) => $document->move($id, $direction), null);
    }

    /** The open page saved a request (names may change) or started a run (History). */
    #[On('collection-saved')]
    #[On('run-started')]
    public function refreshPanel(): void {}

    // --- Internals ---

    private function collectionModel(int $id): Collection
    {
        return $this->project->collections()->findOrFail($id);
    }

    /**
     * Reads the latest file, applies $change, saves, tells the open page.
     *
     * @param  callable(CollectionDocument): mixed  $change
     * @param  array<string, string>  $detail
     */
    private function write(CollectionImporter $importer, int $collection, callable $change, ?string $status, array $detail = []): ?Collection
    {
        $model = $this->collectionModel($collection);

        try {
            $document = $importer->document($model);
            $change($document);
            $warnings = $importer->save($model, $document, $model->sha256);
        } catch (ImportException $e) {
            $this->addError('panel', $e->getMessage());

            return null;
        }

        $this->resetErrorBag('panel');
        $this->dispatch('collection-changed', ...['collection' => $model->id, ...$detail]);
        if ($status !== null || $warnings) {
            $this->dispatch('panel-status', message: trim(($status ?? '').' '.implode(' ', $warnings)));
        }

        return $model->refresh();
    }

    private function openRequest(Collection $collection, ?string $id): void
    {
        if ($id === null) {
            return;
        }
        if ($collection->id === $this->currentCollectionId) {
            $this->dispatch('open-request', id: $id);

            return;
        }
        $this->redirectRoute('projects.collections.show', [$this->project, $collection, 'request' => $id]);
    }

    /**
     * @return list<array{id: string, name: string, folder: bool, depth: int, method: ?string, parent: ?string}>
     */
    private function tree(Collection $collection): array
    {
        // Parsed once per file version: up to 5 MB of JSON on every page otherwise.
        return Cache::rememberForever('collection-tree:'.$collection->sha256, function () use ($collection) {
            try {
                return app(CollectionImporter::class)->document($collection)->tree();
            } catch (ImportException) {
                return [];
            }
        });
    }

    public function render(): View
    {
        $collections = $this->project->collections()->orderBy('name')->get();

        return view('livewire.workspace-panel', [
            'collections' => $collections,
            'trees' => $collections->mapWithKeys(fn (Collection $c) => [$c->id => $this->tree($c)]),
            'environments' => $this->project->environments()->orderBy('name')->get(),
            'activeEnvironmentId' => Workspace::activeEnvironment($this->project)?->id,
            'runs' => $this->project->runs()->latest()->latest('id')->take(15)->get(),
            'buckets' => $this->project->captureBuckets()->with('environment')->orderBy('name')->get(),
        ]);
    }
}
