<?php

namespace App\Livewire;

use App\Enums\EnvironmentType;
use App\Models\Project;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Livewire\Component;

class Environments extends Component
{
    public Project $project;

    public string $name = '';

    public string $slug = '';

    public string $type = 'local';

    public string $base_url_hint = '';

    public function mount(Project $project): void
    {
        $this->project = $project;
    }

    /**
     * @return array<string, mixed>
     */
    protected function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:100'],
            'slug' => ['required', 'string', 'max:100', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/',
                Rule::unique('environments', 'slug')->where('project_id', $this->project->id)],
            'type' => ['required', Rule::enum(EnvironmentType::class)],
            'base_url_hint' => ['nullable', 'url', 'max:255'],
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function messages(): array
    {
        return [
            'slug.regex' => 'The slug may only contain lowercase letters, numbers and single hyphens.',
            'slug.required' => 'Enter a slug — the name has no letters or numbers to build one from.',
            'slug.unique' => 'This project already has an environment with that slug.',
        ];
    }

    public function create(): void
    {
        $this->name = trim($this->name);
        $this->slug = trim($this->slug) !== '' ? trim($this->slug) : Str::slug($this->name);

        // allow_automation is left to the model: false for production (plan section 6).
        $environment = $this->project->environments()->create([
            ...$this->validate(),
            'base_url_hint' => trim($this->base_url_hint) ?: null,
        ]);

        $this->redirectRoute('projects.environments.show', [$this->project, $environment]);
    }

    public function render(): View
    {
        return view('livewire.environments', [
            'environments' => $this->project->environments()->withCount('variables')->orderBy('name')->get(),
            'types' => EnvironmentType::cases(),
        ])->title('Environments · '.$this->project->name);
    }
}
