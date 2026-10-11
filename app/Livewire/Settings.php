<?php

namespace App\Livewire;

use App\Models\Run;
use App\Models\WorkerHeartbeat;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Read-only: shows how the app is configured. Values come from .env and are
 * changed there, never through the UI. The API token itself is never shown.
 */
#[Title('Settings')]
class Settings extends Component
{
    /** Below this, the token is flagged as weak (automation:token makes 64). */
    public const MIN_TOKEN_LENGTH = 32;

    public function render(): View
    {
        $token = (string) config('automation.api_token');
        $heartbeat = WorkerHeartbeat::forConfiguredWorker();

        return view('livewire.settings', [
            'apiEnabled' => $token !== '',
            'tokenIsShort' => $token !== '' && strlen($token) < self::MIN_TOKEN_LENGTH,
            'heartbeat' => $heartbeat,
            'runnerAlive' => $heartbeat?->isFresh() ?? false,
            'cliVersion' => Run::query()->whereNotNull('cli_version')->latest()->value('cli_version'),
            'debug' => (bool) config('app.debug'),
        ]);
    }
}
