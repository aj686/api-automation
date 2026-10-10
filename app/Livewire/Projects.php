<?php

namespace App\Livewire;

use App\Models\Project;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Title('Projects')]
class Projects extends Component
{
    public string $name = '';

    public string $slug = '';

    public string $description = '';

    /**
     * @return array<string, mixed>
     */
    protected function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:100', Rule::unique('projects', 'name')],
            'slug' => ['required', 'string', 'max:100', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/', Rule::unique('projects', 'slug')],
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
            'slug.required' => 'Enter a slug — the name has no letters or numbers to build one from.',
        ];
    }

    public function create(): void
    {
        $this->name = trim($this->name);
        // An empty slug is derived from the name, then validated like a typed one.
        $this->slug = trim($this->slug) !== '' ? trim($this->slug) : Str::slug($this->name);

        $project = Project::create($this->validate());

        $this->redirectRoute('projects.show', $project);
    }

    public function render(): View
    {
        return view('livewire.projects', [
            'projects' => Project::query()
                ->withCount(['environments', 'collections'])
                ->with('latestRun')
                ->orderBy('name')
                ->get(),
        ]);
    }
}
