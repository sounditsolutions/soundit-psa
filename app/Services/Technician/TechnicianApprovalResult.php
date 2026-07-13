<?php

namespace App\Services\Technician;

/**
 * The outcome of an approve or reconnect-run action. status ∈ {sent, closed,
 * published, merged, executed, queued_offline, already_handled, gate_declined,
 * recipient_invalid}. $message carries an operator-facing reason for
 * recipient_invalid (a To/CC that no longer resolves at approval time) or an
 * operator-facing summary for an executed action. $secret is a ONE-TIME
 * credential read back from the executed upstream call (e.g. the CIPP
 * create-user temp password): it exists only on this in-memory result and the
 * JSON response that delivers it to the approver — it is never flashed to the
 * session, stored, or audited.
 */
final class TechnicianApprovalResult
{
    public function __construct(
        public readonly string $status,
        public readonly ?int $noteId = null,
        public readonly ?string $message = null,
        public readonly ?string $secret = null,
    ) {}
}
