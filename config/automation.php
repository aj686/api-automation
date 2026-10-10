<?php

return [

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
