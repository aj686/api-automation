<?php

namespace App\View\Components;

use App\Models\Project;
use Illuminate\Contracts\View\View;
use Illuminate\View\Component;

class ProjectSidebar extends Component
{
    /**
     * Sidebar navigation: add a page here and it appears with its icon.
     * route => [label, icon]; icons are the names in components/icon.blade.php.
     *
     * @var array<string, array{string, string}>
     */
    public const MAIN = [
        'dashboard' => ['Dashboard', 'home'],
        'projects' => ['Projects', 'folder'],
        'runs' => ['Runs', 'play'],
    ];

    /** Pinned to the bottom of the sidebar. @var array<string, array{string, string}> */
    public const FOOTER = [
        'settings' => ['Settings', 'settings'],
    ];

    public function render(): View
    {
        return view('components.project-sidebar', [
            'main' => self::MAIN,
            'footer' => self::FOOTER,
            'projects' => Project::query()->orderBy('name')->get(['id', 'name', 'slug']),
        ]);
    }
}
