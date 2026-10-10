<?php

namespace App\Exceptions;

use App\Models\Run;
use RuntimeException;

/**
 * The same project/environment/collection already has a queued or running
 * run (plan section 8: the API answers 409).
 */
class RunAlreadyActive extends RuntimeException
{
    public function __construct(public readonly Run $run)
    {
        parent::__construct("This collection is already {$run->status->value} against this environment.");
    }
}
