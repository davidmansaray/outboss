<?php

namespace App\Domain\Objectives\Exceptions;

use RuntimeException;

/** The client is acting on a different immutable plan snapshot than the route. */
final class ExecutionPlanSnapshotConflict extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('This execution plan changed after it was loaded. Refresh and review it again.', 409);
    }
}
