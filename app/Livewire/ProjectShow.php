<?php

namespace App\Livewire;

use App\Enums\RunStatus;
use App\Enums\RunTrigger;
use App\Exceptions\RunAlreadyActive;
use App\Models\Project;
use App\Models\Run;
use App\Services\RunService;
use Illuminate\Contracts\View\View;
use Illuminate\Validation\Rule;
use Livewire\Component;

class ProjectShow extends Component
{
    public Project $project;

    public string $name = '';

    public string $slug = '';

    public string $description = '';

    /**
     * Must equal the project name before delete is allowed: deleting removes
     * every environment and its stored credentials.
     */
    public string $confirmName = '';

    // Run panel
    public ?int $runEnvironmentId = null;

    public ?int $runCollectionId = null;

    /** Must equal a production environment's name before it runs (plan section 6). */
    public string $confirmProduction = '';

    public function mount(Project $project): void
    {
        $this->project = $project;
        $this->fill($project->only('name', 'slug'));
        $this->description = (string) $project->description;
        $this->runEnvironmentId = $project->environments()->orderBy('name')->value('id');
        $this->runCollectionId = $project->collections()->orderBy('name')->value('id');
    }

    public function updatedRunEnvironmentId(): void
    {
        $this->confirmProduction = '';
        $this->resetValidation('confirmProduction');
    }

    public function startRun(RunService $runs): void
    {
        $environment = $this->project->environments()->find($this->runEnvironmentId);
        $collection = $this->project->collections()->find($this->runCollectionId);

        if (! $environment || ! $collection) {
            $this->addError('run', 'Choose an environment and a collection.');

            return;
        }

        if ($environment->isProduction() && $this->confirmProduction !== $environment->name) {
            $this->addError('confirmProduction', "Type {$environment->name} to confirm running against production.");

            return;
        }

        try {
            $runs->start($environment, $collection, RunTrigger::Manual);
        } catch (RunAlreadyActive $e) {
            $this->addError('run', $e->getMessage().' Wait for it or cancel it first.');

            return;
        }

        $this->confirmProduction = '';
        $this->resetValidation();
    }

    public function cancelRun(string $runId, RunService $runs): void
    {
        $runs->cancel($this->project->runs()->findOrFail($runId));
    }

    /**
     * @return array<string, mixed>
     */
    protected function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:100', Rule::unique('projects', 'name')->ignore($this->project)],
            'slug' => ['required', 'string', 'max:100', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/', Rule::unique('projects', 'slug')->ignore($this->project)],
            'description' => ['nullable', 'string', 'max:2000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function messages(): array
    {
        return [
            'slug.regex' => 'The slug may only contain lowercase letters, numbers and single hyphens.',
        ];
    }

    public function save(): void
    {
        $this->name = trim($this->name);
        $this->slug = trim($this->slug);

        $slugChanged = $this->slug !== $this->project->slug;
        $this->project->update($this->validate());

        if ($slugChanged) {
            // The URL contains the slug, so the current one no longer exists.
            $this->redirectRoute('projects.show', $this->project);

            return;
        }

        session()->now('status', 'Project saved.');
    }

    public function delete(): void
    {
        if ($this->confirmName !== $this->project->name) {
            $this->addError('confirmName', 'Type the project name exactly to confirm.');

            return;
        }

        $name = $this->project->name;
        $this->project->delete();

        session()->flash('status', "Deleted project {$name}. Its run history was kept.");
        $this->redirectRoute('projects');
    }

    public function render(): View
    {
        $environments = $this->project->environments()->orderBy('name')->get();

        return view('livewire.project-show', [
            'environments' => $environments,
            'collections' => $this->project->collections()->orderBy('name')->get(),
            'selectedEnvironment' => $environments->firstWhere('id', $this->runEnvironmentId),
            'recentRuns' => $this->project->runs()->latest()->latest('id')->limit(5)->get(),
            'hasActiveRun' => $this->project->runs()->whereIn('status', [RunStatus::Queued, RunStatus::Running])->exists(),
            'runCount' => $this->project->runs()->count(),
        ])->title($this->project->name);
    }
}
