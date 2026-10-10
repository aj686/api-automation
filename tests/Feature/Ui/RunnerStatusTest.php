<?php

namespace Tests\Feature\Ui;

use App\Livewire\RunnerStatus;
use App\Models\WorkerHeartbeat;
use App\Support\WorkerHeartbeatRecorder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Queue\Events\Looping;
use Livewire\Livewire;
use Tests\TestCase;

class RunnerStatusTest extends TestCase
{
    use RefreshDatabase;

    private function beatSecondsAgo(int $seconds): void
    {
        WorkerHeartbeat::create(['worker' => config('automation.worker.name'), 'beat_at' => now()->subSeconds($seconds)]);
    }

    public function test_never_seen_runner_shows_the_warning(): void
    {
        Livewire::test(RunnerStatus::class)->assertSee('Runner down');
        Livewire::test(RunnerStatus::class, ['banner' => true])
            ->assertSee('Runner not responding')
            ->assertSee('It has never reported in.');
    }

    public function test_fresh_heartbeat_shows_runner_ok_and_no_banner(): void
    {
        $this->beatSecondsAgo(10);

        Livewire::test(RunnerStatus::class)->assertSee('Runner OK');
        Livewire::test(RunnerStatus::class, ['banner' => true])->assertDontSee('Runner not responding');
    }

    public function test_stale_heartbeat_shows_the_banner_with_last_seen(): void
    {
        $this->beatSecondsAgo(config('automation.worker.stale_after_seconds') + 5);

        Livewire::test(RunnerStatus::class, ['banner' => true])
            ->assertSee('Runner not responding')
            ->assertSee('Last seen');
    }

    public function test_the_banner_is_on_every_page_when_the_runner_is_down(): void
    {
        $this->get('/settings')->assertOk()->assertSee('Runner not responding');
    }

    public function test_queue_loop_writes_a_throttled_heartbeat(): void
    {
        $this->freezeTime();

        event(new Looping('database', 'default'));
        $first = WorkerHeartbeat::forConfiguredWorker()->beat_at;

        $this->travel(5)->seconds();
        event(new Looping('database', 'default'));
        $this->assertEquals($first, WorkerHeartbeat::forConfiguredWorker()->beat_at, 'throttled');

        $this->travel(config('automation.worker.heartbeat_every_seconds'))->seconds();
        event(new Looping('database', 'default'));
        $this->assertTrue(WorkerHeartbeat::forConfiguredWorker()->beat_at->gt($first));
        $this->assertSame(1, WorkerHeartbeat::count());
    }

    public function test_the_looping_listener_never_pauses_the_worker(): void
    {
        // Illuminate\Queue\Worker pauses when a Looping listener returns false.
        $this->assertNotContains(false, event(new Looping('database', 'default')));
        $this->assertInstanceOf(WorkerHeartbeatRecorder::class, app(WorkerHeartbeatRecorder::class));
    }
}
