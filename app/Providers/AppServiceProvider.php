<?php

namespace App\Providers;

use App\Support\StaleRunRecovery;
use App\Support\WorkerHeartbeatRecorder;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(WorkerHeartbeatRecorder::class);
        $this->app->singleton(StaleRunRecovery::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Behind Docker NAT every client shares one IP (plan loophole 29), so
        // this caps the API as a whole rather than per caller.
        RateLimiter::for('automation-api', fn (Request $request) => Limit::perMinute(60)->by($request->ip()));

        // Must not return false: the worker treats a false from a Looping
        // listener as "pause" (Illuminate\Queue\Worker::daemonShouldRun).
        Queue::looping(function (): void {
            rescue(fn () => $this->app->make(StaleRunRecovery::class)->runOnce());
            rescue(fn () => $this->app->make(WorkerHeartbeatRecorder::class)->beatIfDue());
        });
    }
}
