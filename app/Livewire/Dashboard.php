<?php

namespace App\Livewire;

use App\Models\Project;
use App\Support\Workspace;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Title('Dashboard')]
class Dashboard extends Component
{
    public function mount(): void
    {
        // "/" is where the app opens: go back to the last workspace (D-040).
        if (request()->routeIs('dashboard') && ($project = Workspace::remembered())) {
            $this->redirectRoute('projects.show', $project);
        }
    }

    public function render(): View
    {
        return view('livewire.dashboard', [
            'projects' => Project::query()->with('latestRun')->orderBy('name')->get(),
        ]);
    }
}
