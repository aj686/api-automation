<?php

namespace App\Enums;

/**
 * Stored in runs.status, VARCHAR(12). The decision order lives in plan
 * section 5; an exit code alone never produces Pass.
 */
enum RunStatus: string
{
    case Queued = 'QUEUED';
    case Running = 'RUNNING';
    case Pass = 'PASS';
    case Fail = 'FAIL';
    case Error = 'ERROR';
    case Timeout = 'TIMEOUT';
    case Cancelled = 'CANCELLED';

    /**
     * A run in one of these states may still be picked up or still be executing.
     */
    public function isActive(): bool
    {
        return in_array($this, [self::Queued, self::Running], true);
    }

    public function isFinal(): bool
    {
        return ! $this->isActive();
    }

    /**
     * Shown next to the word, never instead of it: status is never conveyed by
     * colour or icon alone (master prompt section 76).
     */
    public function icon(): string
    {
        return match ($this) {
            self::Queued => '○',
            self::Running => '↻',
            self::Pass => '✓',
            self::Fail => '✕',
            self::Error => '⚠',
            self::Timeout => '◷',
            self::Cancelled => '⊘',
        };
    }
}
