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

    // Wall-clock limit for a whole run, enforced by the app, not the CLI.
    'run_timeout_seconds' => (int) env('AUTOMATION_RUN_TIMEOUT_SECONDS', 300),

    // Per-request limit passed to the CLI as --timeout-request.
    'request_timeout_ms' => 30000,

    // After SIGTERM to the CLI's process group, wait this long before SIGKILL.
    'stop_grace_seconds' => 5,

    // Stored log is redacted, then cut to this size (plan section 4).
    'log_max_bytes' => 200_000,

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
