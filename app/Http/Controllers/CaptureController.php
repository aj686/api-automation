<?php

namespace App\Http\Controllers;

use App\Models\CaptureBucket;
use App\Services\CaptureRecorder;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * ANY /capture/{bucket}/{token}/{path?} — called by the system under test
 * (plan section 13). No login: the random token in the URL is the protection
 * (loophole 42), so unknown, disabled and wrong-token requests all get the
 * same 404 and reveal nothing.
 */
class CaptureController extends Controller
{
    public function __invoke(Request $request, CaptureRecorder $recorder, string $bucket, string $token, string $path = ''): Response
    {
        $captureBucket = ctype_digit($bucket) ? CaptureBucket::find((int) $bucket) : null;

        if (! $captureBucket || ! $captureBucket->enabled || ! $captureBucket->tokenMatches($token)) {
            return response()->json(['message' => 'Not Found'], 404);
        }

        $recorder->record($captureBucket, $request, $path);

        $status = $captureBucket->response_status;

        return in_array($status, [204, 304], true)
            ? response()->noContent($status)
            : response()->json(['received' => true], $status);
    }
}
