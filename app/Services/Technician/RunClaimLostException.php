<?php

namespace App\Services\Technician;

use RuntimeException;

/**
 * #6256: thrown inside a gate transaction when TechnicianRun::advanceTo() returns false: this
 * request no longer holds the run's claim, so the transaction rolls back the side effect it
 * wrapped (note, status change, merge, audit row) and the caller reports nothing as done.
 */
final class RunClaimLostException extends RuntimeException
{
    public function __construct(public readonly int $runId)
    {
        parent::__construct('The run was not closed: this request no longer holds its claim.');
    }
}
