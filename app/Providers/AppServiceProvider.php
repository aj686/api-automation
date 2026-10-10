<?php

namespace App\Providers;

use App\Support\WorkerHeartbeatRecorder;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(WorkerHeartbeatRecorder::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Must not return false: the worker treats a false from a Looping
        // listener as "pause" (Illuminate\Queue\Worker::daemonShouldRun).
        Queue::looping(function (): void {
            rescue(fn () => $this->app->make(WorkerHeartbeatRecorder::class)->beatIfDue());
        });
    }
}
