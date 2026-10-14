<?php

namespace App\Support;

use App\Models\Environment;
use App\Models\Project;
use App\Models\Run;
use Illuminate\Http\Request;

/**
 * The project the UI is "in", Postman-workspace style (decisions.md D-040),
 * and its active environment. Both live in the session: the app has no users
 * (D-007), so this is per browser.
 *
 * The workspace is the project in the URL (or the run's project on a run
 * page); elsewhere it is the last one visited, so opening the app goes back
 * there instead of to a project list.
 */
class Workspace
{
    private const PROJECT = 'workspace.project';

    private const ENVIRONMENT = 'workspace.environment.';

    public static function fromRequest(Request $request): ?Project
    {
        $project = $request->route('project');
        if ($project instanceof Project) {
            return $project;
        }

        $run = $request->route('run');
        if ($run instanceof Run) {
            return $run->project;
        }

        return null;
    }

    public static function remember(Project $project): void
    {
        session([self::PROJECT => $project->id]);
    }

    public static function current(?Request $request = null): ?Project
    {
        $request ??= request();

        return self::fromRequest($request) ?? self::remembered();
    }

    public static function remembered(): ?Project
    {
        $id = session(self::PROJECT);

        return $id ? Project::find($id) : null;
    }

    /**
     * The chosen environment; else the first non-production one by name; else
     * the first. Production is never picked for you.
     */
    public static function activeEnvironment(Project $project): ?Environment
    {
        $environments = $project->environments()->orderBy('name')->get();
        $chosen = $environments->firstWhere('id', session(self::ENVIRONMENT.$project->id));

        return $chosen
            ?? $environments->first(fn (Environment $environment) => ! $environment->isProduction())
            ?? $environments->first();
    }

    public static function setActiveEnvironment(Project $project, ?int $environmentId): ?Environment
    {
        $environment = $environmentId ? $project->environments()->find($environmentId) : null;
        if ($environment) {
            session([self::ENVIRONMENT.$project->id => $environment->id]);
        }

        return $environment;
    }
}
