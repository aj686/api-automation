<?php

namespace App\Livewire;

use App\Enums\RunStatus;
use App\Enums\RunTrigger;
use App\Models\Project;
use App\Models\Run;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Run history: every run at /runs, or one project's at /projects/{slug}/runs.
 * Rows are read from the snapshots on the run, so runs of deleted projects,
 * environments and collections stay listed (plan section 4).
 */
class Runs extends Component
{
    use WithPagination;

    /**
     * Status groups, not single statuses (master prompt section 48).
     * Cancelled runs appear only under All.
     *
     * @var array<string, array{0: string, 1: list<RunStatus>|null}>
     */
    public const FILTERS = [
        'all' => ['All', null],
        'passed' => ['Passed', [RunStatus::Pass]],
        'failed' => ['Failed', [RunStatus::Fail]],
        'errors' => ['Errors', [RunStatus::Error, RunStatus::Timeout]],
        'active' => ['Active', [RunStatus::Queued, RunStatus::Running]],
    ];

    public const PER_PAGE = 25;

    /** Set when the page is opened from a project's Runs tab. */
    public ?Project $project = null;

    #[Url(except: 'all')]
    public string $status = 'all';

    /** Project slug filter, only on the all-projects page. */
    #[Url(as: 'project', except: '')]
    public string $projectFilter = '';

    /** Single requests sent from the collection editor are hidden unless asked for (D-038). */
    #[Url(as: 'sends', except: false)]
    public bool $showSends = false;

    public function mount(?Project $project = null): void
    {
        $this->project = $project;
    }

    public function updatedStatus(): void
    {
        $this->resetPage();
    }

    public function updatedProjectFilter(): void
    {
        $this->resetPage();
    }

    public function updatedShowSends(): void
    {
        $this->resetPage();
    }

    public function render(): View
    {
        // An unknown value from the query string falls back to All.
        $statuses = self::FILTERS[$this->status][1] ?? null;
        $projectId = $this->project?->id
            ?? ($this->projectFilter === '' ? null : Project::where('slug', $this->projectFilter)->value('id') ?? 0);

        $runs = Run::query()
            ->when($projectId !== null, fn ($query) => $query->where('project_id', $projectId))
            ->when($statuses, fn ($query) => $query->whereIn('status', $statuses))
            ->when(! $this->showSends, fn ($query) => $query->where('trigger', '!=', RunTrigger::Send))
            ->latest()
            ->latest('id')
            ->paginate(self::PER_PAGE);

        return view('livewire.runs', [
            'runs' => $runs,
            'projects' => $this->project ? collect() : Project::query()->orderBy('name')->get(['id', 'name', 'slug']),
            'hasActiveRun' => $runs->getCollection()->contains(fn (Run $run) => $run->status->isActive()),
        ])->title($this->project ? 'Runs · '.$this->project->name : 'Runs');
    }
}
