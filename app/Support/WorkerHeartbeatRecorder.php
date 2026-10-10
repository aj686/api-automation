<?php

namespace App\Support;

use App\Models\WorkerHeartbeat;
use Illuminate\Support\Carbon;

/**
 * Writes the worker's heartbeat from the queue loop, at most once per
 * automation.worker.heartbeat_every_seconds.
 *
 * Bound as a singleton, so the throttle lives as long as the worker process.
 * The Looping event fires only between jobs; a job that runs longer than
 * stale_after_seconds must beat on its own (decisions.md D-014).
 */
class WorkerHeartbeatRecorder
{
    private ?Carbon $lastBeatAt = null;

    public function beatIfDue(): void
    {
        $every = config('automation.worker.heartbeat_every_seconds');

        if ($this->lastBeatAt && $this->lastBeatAt->diffInSeconds(now()) < $every) {
            return;
        }

        // Set before writing, so a database outage is retried on the next
        // interval instead of on every loop.
        $this->lastBeatAt = now();

        WorkerHeartbeat::beat(config('automation.worker.name'));
    }
}
