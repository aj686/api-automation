<?php

namespace App\Support;

use App\Enums\RunStatus;
use App\Models\Run;
use Illuminate\Support\Facades\File;

/**
 * Runs once when a queue worker starts (first Looping event of the process).
 *
 * There is one worker by design, so a run still RUNNING at that moment was
 * interrupted — the container stopped mid-run and could not update its row
 * (plan section 11). It becomes ERROR. Leftover per-run directories, which
 * may hold environment files with secrets, are deleted.
 */
class StaleRunRecovery
{
    private bool $done = false;

    public function runOnce(): void
    {
        if ($this->done) {
            return;
        }
        $this->done = true;

        Run::query()->where('status', RunStatus::Running)->get()->each(fn (Run $run) => $run->update([
            'status' => RunStatus::Error,
            'error_code' => 'worker_restarted',
            'error_message' => 'The runner stopped while this run was executing, so its result is unknown.',
            'pid' => null,
            'finished_at' => now(),
        ]));

        File::deleteDirectory(config('automation.runs_directory'));
    }
}
