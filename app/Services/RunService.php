<?php

namespace App\Services;

use App\Enums\RunStatus;
use App\Enums\RunTrigger;
use App\Exceptions\RunAlreadyActive;
use App\Jobs\ExecuteRun;
use App\Models\Collection;
use App\Models\Environment;
use App\Models\Run;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * The only way a run starts or is cancelled — used by the UI now and the API
 * in phase 13. Nothing executes here: the run is queued and the worker
 * container picks it up (plan section 1).
 */
class RunService
{
    public function start(Environment $environment, Collection $collection, RunTrigger $trigger): Run
    {
        if ($environment->project_id !== $collection->project_id) {
            throw new InvalidArgumentException('The environment and collection belong to different projects.');
        }

        $run = DB::transaction(function () use ($environment, $collection, $trigger) {
            // Serialises concurrent starts for the same target.
            $active = Run::query()
                ->where('environment_id', $environment->id)
                ->where('collection_id', $collection->id)
                ->whereIn('status', [RunStatus::Queued, RunStatus::Running])
                ->lockForUpdate()
                ->first();

            if ($active) {
                throw new RunAlreadyActive($active);
            }

            $project = $environment->project;

            return Run::create([
                'project_id' => $project->id,
                'environment_id' => $environment->id,
                'collection_id' => $collection->id,
                'project_name' => $project->name,
                'environment_name' => $environment->name,
                'environment_type' => $environment->type->value,
                'collection_name' => $collection->name,
                'status' => RunStatus::Queued,
                'trigger' => $trigger,
                'timeout_seconds' => config('automation.run_timeout_seconds'),
            ]);
        });

        ExecuteRun::dispatch($run->id)->afterCommit();

        return $run;
    }

    /**
     * Asks for a stop. A queued run is cancelled when the worker reaches it; a
     * running one when the worker has confirmed its processes are gone. The
     * status only changes once that is true (plan section 5).
     */
    public function cancel(Run $run): void
    {
        if ($run->status->isActive() && $run->cancel_requested_at === null) {
            $run->update(['cancel_requested_at' => now()]);
        }
    }
}
