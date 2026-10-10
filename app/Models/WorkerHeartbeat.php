<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\WithoutTimestamps;
use Illuminate\Database\Eloquent\Model;

/**
 * Last sign of life from a queue worker; the UI warns when it goes stale.
 */
#[Fillable(['worker', 'beat_at'])]
#[WithoutTimestamps]
class WorkerHeartbeat extends Model
{
    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'beat_at' => 'datetime',
        ];
    }

    public static function beat(string $worker): void
    {
        static::updateOrCreate(['worker' => $worker], ['beat_at' => now()]);
    }

    /**
     * The configured worker's heartbeat, or null if it never beat.
     */
    public static function forConfiguredWorker(): ?self
    {
        return static::where('worker', config('automation.worker.name'))->first();
    }

    public function isFresh(): bool
    {
        return $this->beat_at->gt(now()->subSeconds(config('automation.worker.stale_after_seconds')));
    }
}
