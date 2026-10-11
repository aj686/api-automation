<?php

namespace App\Http\Controllers\Api;

use App\Enums\RunTrigger;
use App\Exceptions\RunAlreadyActive;
use App\Http\Controllers\Controller;
use App\Models\Project;
use App\Models\Run;
use App\Services\RunService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Start a run and read its result, for n8n (plan section 8, decisions.md D-030).
 *
 * n8n never parses CLI output: GET returns the status, counts and failing
 * assertions as structured, already-redacted data (master prompt section 39).
 */
class RunController extends Controller
{
    /** Failing rows returned by show(); the run page has the rest. */
    private const MAX_FAILURES = 50;

    public function store(Request $request, RunService $runs): JsonResponse
    {
        $data = $request->validate([
            'project' => ['required', 'string', 'max:100'],
            'environment' => ['required', 'string', 'max:100'],
            'collection' => ['required', 'string', 'max:150'],
        ]);

        $project = Project::where('slug', $data['project'])->first();
        $environment = $project?->environments()->where('slug', $data['environment'])->first();
        $collection = $project?->collections()->where('slug', $data['collection'])->first();

        $unknown = array_filter([
            'project' => $project ? null : 'No project with this slug.',
            'environment' => $project && ! $environment ? 'This project has no environment with this slug.' : null,
            'collection' => $project && ! $collection ? 'This project has no collection with this slug.' : null,
        ]);
        if ($unknown) {
            throw ValidationException::withMessages($unknown);
        }

        // Production is excluded unless someone allowed it on the environment page (plan section 6).
        if (! $environment->allow_automation) {
            return response()->json([
                'message' => "Automation is not allowed for the environment {$environment->slug}"
                    .($environment->isProduction() ? ' (production)' : '').'. Allow it on its page in the app, or run it from the UI.',
            ], 403);
        }

        try {
            $run = $runs->start($environment, $collection, RunTrigger::Api);
        } catch (RunAlreadyActive $e) {
            return response()->json([
                'message' => $e->getMessage(),
                'id' => $e->run->id,
                'status' => $e->run->status->value,
                'status_url' => route('api.runs.show', $e->run),
            ], 409);
        }

        return response()->json([
            'id' => $run->id,
            'status' => $run->status->value,
            'status_url' => route('api.runs.show', $run),
        ], 202, ['Location' => route('api.runs.show', $run)]);
    }

    public function show(Run $run): JsonResponse
    {
        $failures = $run->results()->where('passed', false)->limit(self::MAX_FAILURES)->get();

        return response()->json([
            'id' => $run->id,
            'status' => $run->status->value,
            // True once the status will not change; poll until then.
            'finished' => $run->status->isFinal(),
            'no_tests' => $run->hasNoTests(),
            'project' => $run->project_name,
            'environment' => $run->environment_name,
            'environment_type' => $run->environment_type,
            'collection' => $run->collection_name,
            'trigger' => $run->trigger->value,
            'created_at' => $run->created_at?->toIso8601String(),
            'started_at' => $run->started_at?->toIso8601String(),
            'finished_at' => $run->finished_at?->toIso8601String(),
            'duration_ms' => $run->duration_ms,
            // null means unknown, never zero (plan section 4).
            'summary' => [
                'requests' => $run->total_requests,
                'assertions' => $run->total_assertions,
                'passed' => $run->passed_assertions,
                'failed' => $run->failed_assertions,
            ],
            'error' => $run->error_code === null && $run->error_message === null
                ? null
                : ['code' => $run->error_code, 'message' => $run->error_message],
            'failures' => $failures->map(fn ($result) => [
                'request' => $result->item_name,
                'method' => $result->method,
                'url' => $result->url,
                'response_code' => $result->response_code,
                'assertion' => $result->assertion,
                'message' => $result->error_message,
            ])->values(),
            'web_url' => route('runs.show', $run),
        ]);
    }
}
