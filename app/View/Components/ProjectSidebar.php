<?php

namespace App\View\Components;

use App\Models\Project;
use App\Support\Workspace;
use Illuminate\Contracts\View\View;
use Illuminate\View\Component;

/**
 * Icon rail + side panel, Postman/Requestly style (decisions.md D-040).
 * In a workspace the rail switches the panel between the project's
 * collections, environments, history and capture URLs; without one the panel
 * lists the projects.
 */
class ProjectSidebar extends Component
{
    /**
     * Panel sections of a workspace: key => [label, icon].
     *
     * @var array<string, array{string, string}>
     */
    public const SECTIONS = [
        'collections' => ['Collections', 'collection'],
        'environments' => ['Environments', 'environment'],
        'history' => ['History', 'history'],
        'captures' => ['Captures', 'capture'],
    ];

    /**
     * Pages, pinned to the bottom of the rail: route => [label, icon].
     *
     * @var array<string, array{string, string}>
     */
    public const MAIN = [
        'home' => ['Dashboard', 'home'],
        'runs' => ['All runs', 'play'],
    ];

    /** @var array<string, array{string, string}> */
    public const FOOTER = [
        'settings' => ['Settings', 'settings'],
    ];

    /** Which section a page opens the panel on; others keep the last one picked. */
    private const SECTION_FOR_ROUTE = [
        'projects.environments*' => 'environments',
        'projects.runs' => 'history',
        'runs.show' => 'history',
        'projects.captures' => 'captures',
        'projects.collections*' => 'collections',
    ];

    public function render(): View
    {
        $section = null;
        foreach (self::SECTION_FOR_ROUTE as $pattern => $candidate) {
            if (request()->routeIs($pattern)) {
                $section = $candidate;
                break;
            }
        }

        return view('components.project-sidebar', [
            'workspace' => Workspace::current(),
            'sections' => self::SECTIONS,
            'main' => self::MAIN,
            'footer' => self::FOOTER,
            'routeSection' => $section,
            'currentCollectionId' => request()->route('collection')?->id,
            'projects' => Project::query()->orderBy('name')->get(['id', 'name', 'slug']),
        ]);
    }
}
