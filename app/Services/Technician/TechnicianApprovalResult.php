<?php

namespace App\Services\Technician;

/**
 * The outcome of an approve or reconnect-run action. status ∈ {sent, closed,
 * published, merged, executed, queued_offline, already_handled, gate_declined}.
 */
final class TechnicianApprovalResult
{
    public function __construct(
        public readonly string $status,
        public readonly ?int $noteId = null,
    ) {}
}
