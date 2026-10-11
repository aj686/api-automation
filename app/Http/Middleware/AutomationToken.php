<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * `Authorization: Bearer <AUTOMATION_API_TOKEN>` for /api/v1 (plan section 6).
 *
 * The API is off until a token is configured. Both sides are hashed before
 * hash_equals() so the comparison takes the same time whatever the length of
 * the guess.
 */
class AutomationToken
{
    public function handle(Request $request, Closure $next): Response
    {
        $configured = (string) config('automation.api_token');

        if ($configured === '') {
            return response()->json([
                'message' => 'The automation API is disabled: AUTOMATION_API_TOKEN is not set. Run php artisan automation:token.',
            ], 503);
        }

        $given = (string) $request->bearerToken();

        if ($given === '' || ! hash_equals(hash('sha256', $configured), hash('sha256', $given))) {
            return response()->json(['message' => 'Missing or invalid API token.'], 401, ['WWW-Authenticate' => 'Bearer']);
        }

        return $next($request);
    }
}
