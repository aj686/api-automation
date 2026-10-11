<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CapturedRequest;
use App\Models\Project;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * GET /api/v1/captures/{project}/{bucket}?since=… — what a capture URL received,
 * for Postman to assert on (plan section 13, decisions.md D-032).
 *
 * `since` filters out calls from earlier runs (loophole 44); collections pass
 * {{run_started_at}}, which the runner sets for every run.
 */
class CaptureController extends Controller
{
    private const LIMIT = 50;

    public function __invoke(Request $request, Project $project, string $bucket): JsonResponse
    {
        $request->validate(['since' => ['nullable', 'date']]);

        $captureBucket = $project->captureBuckets()->where('slug', $bucket)->firstOrFail();
        $captureBucket->pruneExpired();

        $captures = $captureBucket->requests()
            ->when($request->filled('since'), fn ($q) => $q->where('received_at', '>=', Carbon::parse($request->string('since'))))
            ->orderByDesc('received_at')->orderByDesc('id')
            ->limit(self::LIMIT)
            ->get()
            ->reverse()
            ->values();

        return response()->json([
            'bucket' => ['slug' => $captureBucket->slug, 'name' => $captureBucket->name, 'enabled' => $captureBucket->enabled],
            'captures' => $captures->map(fn (CapturedRequest $c) => [
                'id' => $c->id,
                'received_at' => $c->received_at->toIso8601ZuluString('millisecond'),
                'method' => $c->method,
                'path' => $c->path,
                'query' => $c->query,
                'headers' => $c->headers,
                'body' => $c->body,
                // Parsed when the body is JSON, so assertions can read fields directly.
                'json' => $c->body !== null ? json_decode($c->body, true) : null,
                'body_truncated' => $c->body_truncated,
            ]),
        ]);
    }
}
