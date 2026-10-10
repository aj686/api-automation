<?php

namespace App\View\Components;

use App\Models\Project;
use Illuminate\Contracts\View\View;
use Illuminate\View\Component;

class ProjectSidebar extends Component
{
    public function render(): View
    {
        return view('components.project-sidebar', [
            'projects' => Project::query()->orderBy('name')->get(['id', 'name', 'slug']),
        ]);
    }
}
