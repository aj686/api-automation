<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Project;
use Illuminate\Http\JsonResponse;

/**
 * GET /api/v1/catalog — the slugs n8n needs to start a run (plan section 8).
 * Names and slugs only: no variables, values or file paths.
 */
class CatalogController extends Controller
{
    public function __invoke(): JsonResponse
    {
        $projects = Project::query()
            ->with(['environments' => fn ($q) => $q->orderBy('name'), 'collections' => fn ($q) => $q->orderBy('name')])
            ->orderBy('name')
            ->get();

        return response()->json([
            'projects' => $projects->map(fn (Project $project) => [
                'slug' => $project->slug,
                'name' => $project->name,
                'environments' => $project->environments->map(fn ($environment) => [
                    'slug' => $environment->slug,
                    'name' => $environment->name,
                    'type' => $environment->type->value,
                    'allow_automation' => $environment->allow_automation,
                ])->values(),
                'collections' => $project->collections->map(fn ($collection) => [
                    'slug' => $collection->slug,
                    'name' => $collection->name,
                    'kind' => $collection->kind->value,
                ])->values(),
            ])->values(),
        ]);
    }
}
