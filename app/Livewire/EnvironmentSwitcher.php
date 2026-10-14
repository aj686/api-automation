<?php

namespace App\Livewire;

use App\Models\Project;
use App\Support\Workspace;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * The active environment, top right like Postman (decisions.md D-040).
 * Send, Run and the Run panel use it; changing it tells them with
 * `environment-changed`.
 */
class EnvironmentSwitcher extends Component
{
    #[Locked]
    public Project $project;

    public ?int $environmentId = null;

    public function mount(Project $project): void
    {
        $this->project = $project;
        $this->environmentId = Workspace::activeEnvironment($project)?->id;
    }

    public function updatedEnvironmentId(): void
    {
        $environment = Workspace::setActiveEnvironment($this->project, $this->environmentId);
        $this->environmentId = $environment?->id ?? Workspace::activeEnvironment($this->project)?->id;
        $this->dispatch('environment-changed', environment: $this->environmentId);
    }

    /** Another part of the page (the side panel's ✓, the Run panel) chose one. */
    #[On('environment-chosen')]
    public function chosen(int $environment): void
    {
        $this->environmentId = $environment;
        $this->updatedEnvironmentId();
    }

    public function render(): View
    {
        return view('livewire.environment-switcher', [
            'environments' => $this->project->environments()->orderBy('name')->get(),
        ]);
    }
}
