<?php

namespace App\Http\Middleware;

use App\Support\Workspace;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * A page of a project (or of one of its runs) makes it the workspace that
 * opening the app returns to (decisions.md D-040).
 */
class RememberWorkspace
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($project = Workspace::fromRequest($request)) {
            Workspace::remember($project);
        }

        return $next($request);
    }
}
