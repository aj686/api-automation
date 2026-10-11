<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Postman CLI
    |--------------------------------------------------------------------------
    |
    | The executable comes from .env only (CLAUDE.md hard rules). Flags and
    | behaviour were verified against 1.71.0 (decisions.md D-022).
    |
    */

    'postman_cli_path' => env('POSTMAN_CLI_PATH', 'postman'),

    // Node, for scripts/compact-postman-report.mjs (it exists in the runner image for Postman CLI).
    'node_path' => env('AUTOMATION_NODE_PATH', 'node'),

    /*
    |--------------------------------------------------------------------------
    | Automation API
    |--------------------------------------------------------------------------
    |
    | Bearer token for /api/v1 (n8n). Empty means the API is disabled. Set it
    | with `php artisan automation:token`; never commit it (plan section 6).
    |
    */

    'api_token' => env('AUTOMATION_API_TOKEN'),

    // Wall-clock limit for a whole run, enforced by the app, not the CLI.
    'run_timeout_seconds' => (int) env('AUTOMATION_RUN_TIMEOUT_SECONDS', 300),

    // Per-request limit passed to the CLI as --timeout-request.
    'request_timeout_ms' => 30000,

    // After SIGTERM to the CLI's process group, wait this long before SIGKILL.
    'stop_grace_seconds' => 5,

    // Stored log is redacted, then cut to this size (plan section 4).
    'log_max_bytes' => 200_000,

    // Request and response bodies: each cut at this size; headers and bodies
    // are deleted after this many days, the request line and tests are kept (D-037).
    'payload_max_bytes' => 65_536,
    'payload_retention_days' => 30,

    // Per-run scratch directories (environment file, report); deleted after each run.
    'runs_directory' => storage_path('app/private/runs'),

    /*
    |--------------------------------------------------------------------------
    | Queue worker heartbeat
    |--------------------------------------------------------------------------
    |
    | The worker records a heartbeat while it loops; the UI warns "Runner not
    | responding" when the last beat is older than stale_after_seconds
    | (plan section 11, loophole 25). One worker by design, so one name.
    |
    */

    'worker' => [
        'name' => 'api-automation-worker',
        'heartbeat_every_seconds' => 30,
        'stale_after_seconds' => 90,
    ],

];
