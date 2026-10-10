<?php

namespace App\Services\Postman;

/**
 * Signals and inspects a Linux process group (the CLI is started with setsid,
 * so its group id equals its pid).
 *
 * "Alive" ignores zombies: in a container whose PID 1 does not reap orphans
 * (php-fpm), a killed child can linger as a zombie, which kill(pid, 0) would
 * still report as existing.
 */
class ProcessGroup
{
    public function __construct(private readonly int $pgid) {}

    public function alive(): bool
    {
        foreach (glob('/proc/[0-9]*/stat') ?: [] as $statFile) {
            $stat = @file_get_contents($statFile);
            if ($stat === false) {
                continue;
            }

            // Format: "pid (comm) state ppid pgrp …"; comm may contain spaces or ')'.
            $fields = explode(' ', substr($stat, strrpos($stat, ')') + 2));
            if ((int) ($fields[2] ?? 0) === $this->pgid && ($fields[0] ?? 'Z') !== 'Z') {
                return true;
            }
        }

        return false;
    }

    /**
     * SIGTERM to the whole group, then SIGKILL after the grace period.
     * Returns true only once no live process of the group remains.
     */
    public function stop(int $graceSeconds): bool
    {
        if (! $this->alive()) {
            return true;
        }

        @posix_kill(-$this->pgid, SIGTERM);
        if ($this->waitUntilGone($graceSeconds)) {
            return true;
        }

        @posix_kill(-$this->pgid, SIGKILL);

        return $this->waitUntilGone(5);
    }

    private function waitUntilGone(int $seconds): bool
    {
        $deadline = microtime(true) + $seconds;

        do {
            if (! $this->alive()) {
                return true;
            }
            usleep(100_000);
        } while (microtime(true) < $deadline);

        return ! $this->alive();
    }
}
