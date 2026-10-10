<?php

namespace App\Livewire;

use App\Models\WorkerHeartbeat;
use Illuminate\Contracts\View\View;
use Livewire\Component;

/**
 * Header pill ("Runner OK") or, with banner=true, the full-width warning.
 * Polls every 30 s — the heartbeat itself is only written every 30 s.
 */
class RunnerStatus extends Component
{
    public bool $banner = false;

    public function render(): View
    {
        $heartbeat = WorkerHeartbeat::forConfiguredWorker();

        return view('livewire.runner-status', [
            'heartbeat' => $heartbeat,
            'alive' => $heartbeat?->isFresh() ?? false,
        ]);
    }
}
