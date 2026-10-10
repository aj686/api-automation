<?php

namespace App\Livewire;

use App\Models\Project;
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

    public function mount(Project $project): void
    {
        $this->project = $project;
        $this->fill($project->only('name', 'slug'));
        $this->description = (string) $project->description;
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

        session()->flash('status', 'Project saved.');
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
        return view('livewire.project-show', [
            'environments' => $this->project->environments()->orderBy('name')->get(),
            'collectionCount' => $this->project->collections()->count(),
            'latestRun' => $this->project->latestRun()->first(),
            'runCount' => $this->project->runs()->count(),
        ])->title($this->project->name);
    }
}
