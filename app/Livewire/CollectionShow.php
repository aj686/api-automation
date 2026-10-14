<?php

namespace App\Livewire;

use App\Enums\RunTrigger;
use App\Exceptions\ImportException;
use App\Exceptions\RunAlreadyActive;
use App\Models\Collection;
use App\Models\Project;
use App\Models\Run;
use App\Services\CollectionImporter;
use App\Services\Postman\CollectionDocument;
use App\Services\Postman\PostmanUrl;
use App\Services\RunOptions;
use App\Services\RunService;
use App\Support\Workspace;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Str;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * One collection as a Postman-style workbench (decisions.md D-038): the tree,
 * one request's tabs, Save and Send, and the Runner with its Run sequence.
 *
 * The app edits the collection JSON only. Send and Run both go through the
 * normal runner, so Postman CLI still resolves variables, runs scripts and
 * does auth. The document is read from storage on each action, never kept in
 * component state; only the open request's form is.
 */
class CollectionShow extends Component
{
    private const NAME_MAX = 250;

    #[Locked]
    public Project $project;

    #[Locked]
    public Collection $collection;

    /** request | runner | overview; ?pane=runner opens the Runner. */
    #[Url(except: 'request')]
    public string $pane = 'request';

    /** The open request; ?request=<id> opens it (links from the side panel, D-040). */
    #[Url(as: 'request', except: null)]
    public ?string $selectedId = null;

    /** The open request, as CollectionDocument::requestForm() returns it. @var array<string, mixed> */
    public array $form = [];

    /** True once the open request has edits that are not saved. Not "dirty": Livewire reserves it. */
    public bool $unsaved = false;

    /** The file's hash when the form was read; Save refuses if it changed. */
    #[Locked]
    public string $baseSha = '';

    /** The workspace's active environment, chosen in the top bar (D-040). */
    #[Locked]
    public ?int $environmentId = null;

    public string $confirmProduction = '';

    // Runner
    /** @var list<array{id: string, name: string, method: ?string, folder: ?string, checked: bool}> */
    public array $sequence = [];

    public bool $bail = false;

    /** Save what scripts set (e.g. a login token) to the environment; on by default, like Send (D-039). */
    public bool $keepVariables = true;

    #[Locked]
    public ?string $sendRunId = null;

    /** Safety warnings from the last save; counts only, never values. @var list<string> */
    public array $warnings = [];

    public function mount(Project $project, Collection $collection): void
    {
        $this->project = $project;
        $this->collection = $collection;
        $this->baseSha = $collection->sha256;

        $this->environmentId = Workspace::activeEnvironment($project)?->id;

        $document = $this->document();
        $this->resetSequence($document);
        if (! in_array($this->pane, ['request', 'runner', 'overview'], true)) {
            $this->pane = 'request';
        }
        $open = $this->selectedId !== null && $document->isRequest($this->selectedId)
            ? $this->selectedId
            : ($document->requests()[0]['id'] ?? null);
        if ($open !== null) {
            $this->openRequest($document, $open);
        } else {
            $this->selectedId = null;
            if ($this->pane === 'request') {
                $this->pane = 'overview';
            }
        }
    }

    // --- Panes and selection ---

    public function showPane(string $pane): void
    {
        if (in_array($pane, ['request', 'runner', 'overview'], true)) {
            $this->pane = $pane;
        }
    }

    public function selectItem(string $id): void
    {
        $document = $this->document();
        if ($document->isRequest($id)) {
            $this->openRequest($document, $id);
            $this->pane = 'request';
            $this->dispatch('request-opened', id: $id);
        }
    }

    /** The top bar (or the side panel's ✓) picked another environment. */
    #[On('environment-changed')]
    public function environmentChanged(?int $environment = null): void
    {
        $this->environmentId = $environment;
        $this->confirmProduction = '';
        $this->resetValidation('confirmProduction');
    }

    /**
     * The side panel edited this collection's tree. Its own write, so the new
     * file is the base; the open form follows unless it has unsaved edits.
     */
    #[On('collection-changed')]
    public function collectionChanged(int $collection, ?string $renamed = null, ?string $name = null): void
    {
        if ($collection !== $this->collection->id) {
            return;
        }

        $document = $this->document();
        $this->baseSha = $this->collection->sha256;
        $this->resetSequence($document);

        if ($this->selectedId !== null && ! $document->isRequest($this->selectedId)) {
            $this->reset('selectedId', 'form', 'unsaved', 'sendRunId');
            $this->pane = 'overview';
        } elseif ($this->selectedId !== null && ! $this->unsaved) {
            $this->form = $document->requestForm($this->selectedId);
        } elseif ($renamed !== null && $renamed === $this->selectedId) {
            $this->form['name'] = (string) $name;
        }
    }

    /**
     * Any edit in the form marks it unsaved; the URL and Params tab stay in step.
     */
    public function updated(string $property): void
    {
        if (! str_starts_with($property, 'form.')) {
            return;
        }
        $this->unsaved = true;

        if ($property === 'form.url') {
            $disabled = array_values(array_filter($this->form['params'], fn ($p) => ! $p['enabled']));
            $this->form['params'] = [...array_map(fn ($p) => $p + ['description' => ''], PostmanUrl::queryFromRaw((string) $this->form['url'])), ...$disabled];
        } elseif (str_starts_with($property, 'form.params')) {
            $this->form['url'] = PostmanUrl::withQuery((string) $this->form['url'], $this->form['params']);
        }
    }

    // --- Table rows (params, headers, body fields) ---

    public function addRow(string $list): void
    {
        $rows = $this->rowList($list);
        $rows[] = ['key' => '', 'value' => '', 'enabled' => true, 'description' => ''];
        data_set($this->form, $list, $rows);
        $this->unsaved = true;
    }

    public function removeRow(string $list, int $index): void
    {
        $rows = $this->rowList($list);
        unset($rows[$index]);
        data_set($this->form, $list, array_values($rows));
        $this->unsaved = true;
        if ($list === 'params') {
            $this->form['url'] = PostmanUrl::withQuery((string) $this->form['url'], $this->form['params']);
        }
    }

    // --- Save and Send ---

    public function saveRequest(CollectionImporter $importer): bool
    {
        if ($this->selectedId === null) {
            return false;
        }

        $this->validate([
            'form.name' => ['required', 'string', 'max:'.self::NAME_MAX],
            'form.method' => ['required', 'string', 'max:10', 'regex:/^[A-Za-z]+$/'],
            'form.url' => ['nullable', 'string', 'max:8000'],
        ], ['form.method.regex' => 'The method may only contain letters.'], ['form.name' => 'name', 'form.method' => 'method', 'form.url' => 'URL']);

        $saved = $this->write($importer, function (CollectionDocument $document) {
            $document->applyRequestForm($this->selectedId, $this->form);
        }, 'Saved '.$this->form['name'].'.', checkBase: true);
        if ($saved) {
            $this->unsaved = false;
            $this->dispatch('collection-saved')->to(WorkspacePanel::class);
        }

        return $saved;
    }

    public function sendRequest(CollectionImporter $importer, RunService $runs): void
    {
        if ($this->selectedId === null) {
            return;
        }
        if ($this->unsaved && ! $this->saveRequest($importer)) {
            return;
        }

        // Send always keeps what scripts set, like Postman: Login once, then Send the rest.
        $run = $this->start($runs, RunTrigger::Send, new RunOptions([['id' => $this->selectedId, 'name' => Str::limit((string) $this->form['name'], self::NAME_MAX, '')]], keepVariables: true));
        if ($run) {
            $this->sendRunId = $run->id;
        }
    }

    // --- Runner: Run sequence for this run only (not saved) ---

    /**
     * Drag and drop (wire:sort): $item moved to $position.
     */
    public function sortSequence(string $item, int $position): void
    {
        $from = array_search($item, array_column($this->sequence, 'id'), true);
        if ($from === false) {
            return;
        }
        $row = array_splice($this->sequence, $from, 1)[0];
        array_splice($this->sequence, max(0, min($position, count($this->sequence))), 0, [$row]);
    }

    /**
     * The ↑/↓ buttons, for keyboards.
     */
    public function moveInSequence(int $index, int $direction): void
    {
        $to = $index + ($direction < 0 ? -1 : 1);
        if (isset($this->sequence[$index], $this->sequence[$to])) {
            [$this->sequence[$index], $this->sequence[$to]] = [$this->sequence[$to], $this->sequence[$index]];
        }
    }

    public function tickAll(bool $checked): void
    {
        foreach ($this->sequence as $i => $row) {
            $this->sequence[$i]['checked'] = $checked;
        }
    }

    public function resetSequence(?CollectionDocument $document = null): void
    {
        $document ??= $this->document();
        $this->sequence = array_map(fn (array $r) => $r + ['checked' => true], $document->requests());
    }

    public function runSequence(RunService $runs): void
    {
        $chosen = array_values(array_filter($this->sequence, fn ($row) => $row['checked']));
        if ($chosen === []) {
            $this->addError('sequence', 'Tick at least one request to run.');

            return;
        }

        $all = array_column($this->document()->requests(), 'id');
        $options = RunOptions::forSelection(array_map(fn ($row) => ['id' => $row['id'], 'name' => Str::limit($row['name'], self::NAME_MAX, '')], $chosen), $all, $this->bail, $this->keepVariables);

        if ($run = $this->start($runs, RunTrigger::Manual, $options)) {
            $this->redirectRoute('runs.show', $run);
        }
    }

    // --- Internals ---

    private function document(): CollectionDocument
    {
        $this->collection->refresh();

        return app(CollectionImporter::class)->document($this->collection);
    }

    private function openRequest(CollectionDocument $document, string $id): void
    {
        $this->selectedId = $id;
        $this->form = $document->requestForm($id);
        $this->unsaved = false;
        $this->baseSha = $this->collection->sha256;
        $this->sendRunId = null;
        $this->resetValidation();
    }

    /**
     * Reads the file, applies $change, saves. On success the Run sequence
     * follows the new tree (edits there are dropped: they are per run).
     * Tree edits work on the latest file; Save ($checkBase) only on the file
     * the form was read from, so it never overwrites a change made elsewhere.
     *
     * @param  callable(CollectionDocument): mixed  $change
     */
    private function write(CollectionImporter $importer, callable $change, ?string $status, bool $checkBase = false): bool
    {
        $this->warnings = [];

        try {
            $document = $this->document();
            if ($checkBase && ! hash_equals($this->collection->sha256, $this->baseSha)) {
                throw new ImportException('This collection was changed elsewhere (another tab or Replace file). Reload the page; your unsaved edits are lost.');
            }
            $change($document);
            $this->warnings = $importer->save($this->collection, $document, $this->collection->sha256);
        } catch (ImportException $e) {
            $this->addError('collection', $e->getMessage());

            return false;
        }

        $this->collection->refresh();
        $this->baseSha = $this->collection->sha256;
        $document = $this->document();

        if ($this->selectedId !== null && ! $document->isRequest($this->selectedId)) {
            $this->reset('selectedId', 'form', 'unsaved', 'sendRunId');
            $this->pane = 'overview';
        }
        $this->resetSequence($document);

        if ($status !== null) {
            session()->now('status', $status);
        }

        return true;
    }

    private function start(RunService $runs, RunTrigger $trigger, RunOptions $options): ?Run
    {
        $environment = $this->project->environments()->find($this->environmentId);
        if (! $environment) {
            $this->addError('run', 'Choose an environment first.');

            return null;
        }

        if ($environment->isProduction() && $this->confirmProduction !== $environment->name) {
            $this->addError('confirmProduction', "Type {$environment->name} to confirm running against production.");

            return null;
        }

        try {
            $run = $runs->start($environment, $this->collection, $trigger, $options);
        } catch (RunAlreadyActive $e) {
            $this->addError('run', $e->getMessage().' Wait for it or cancel it first.');

            return null;
        }

        $this->confirmProduction = '';
        $this->resetErrorBag(['run', 'confirmProduction', 'sequence']);
        $this->dispatch('run-started')->to(WorkspacePanel::class);

        return $run;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function rowList(string $list): array
    {
        if (! in_array($list, ['params', 'headers', 'body.fields'], true)) {
            abort(400);
        }

        return array_values((array) data_get($this->form, $list, []));
    }

    public function render(): View
    {
        $document = $this->document();
        $environments = $this->project->environments()->orderBy('name')->get();
        $sendRun = $this->sendRunId ? Run::with('requests.results')->find($this->sendRunId) : null;

        $page = route('projects.collections.show', [$this->project, $this->collection]);
        $tab = match (true) {
            $this->pane === 'request' && $this->selectedId !== null => [
                'key' => 'req:'.$this->collection->id.':'.$this->selectedId, 'kind' => 'request',
                'label' => (string) ($this->form['name'] ?? ''), 'method' => (string) ($this->form['method'] ?? ''),
                'url' => $page.'?request='.rawurlencode($this->selectedId), 'request' => $this->selectedId, 'unsaved' => $this->unsaved,
            ],
            $this->pane === 'runner' => ['key' => 'runner:'.$this->collection->id, 'kind' => 'runner', 'label' => 'Runner · '.$this->collection->name, 'url' => $page.'?pane=runner', 'pane' => 'runner'],
            default => ['key' => 'col:'.$this->collection->id, 'kind' => 'collection', 'label' => $this->collection->name, 'url' => $page.'?pane=overview', 'pane' => 'overview'],
        };

        return view('livewire.collection-show', [
            'tab' => $tab + ['collection' => $this->collection->id],
            'tree' => $document->tree(),
            'summary' => $document->collectionSummary(),
            'environments' => $environments,
            'selectedEnvironment' => $environments->firstWhere('id', $this->environmentId),
            'sendRun' => $sendRun,
            'methods' => CollectionDocument::METHODS,
            'languages' => CollectionDocument::RAW_LANGUAGES,
            'authTypes' => CollectionDocument::AUTH_TYPES,
            'bodyModes' => CollectionDocument::BODY_MODES,
            'changedElsewhere' => $this->selectedId !== null && ! hash_equals($this->collection->sha256, $this->baseSha),
        ])->title($this->collection->name.' · '.$this->project->name);
    }
}
