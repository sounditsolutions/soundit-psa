<?php

namespace App\Services\Technician;

/**
 * The outcome of an approve or reconnect-run action. status ∈ {sent, closed,
 * resolved, published, merged, executed, executed_with_fault, queued_offline,
 * already_handled, gate_declined, recipient_invalid}. 'resolved' vs 'closed'
 * distinguishes which terminal target an approved close_ticket run applied
 * (psa-d9ayt). 'executed_with_fault' is an upstream write that LANDED but
 * violated its post-condition (the Huntress resolution_method hard fault), or
 * one a duplicate brake suppressed so that the run wrote NOTHING while the
 * state it proposed already exists upstream under another proposal's terms
 * (the Mesh allow-rule idempotent no-op, #1133): the
 * run is terminal, yet the cockpit must render $message on the ERROR channel —
 * never as a success, never as a decline. $message
 * carries an operator-facing reason for
 * recipient_invalid (a To/CC that no longer resolves at approval time) or an
 * operator-facing summary for an executed action. $secret is a ONE-TIME
 * credential read back from the executed upstream call — the CIPP create-user
 * temp password, or a freshly-minted MeshCentral remote-control URL (psa-5s4r2):
 * it exists only on this in-memory result and the JSON response that delivers it
 * to the approver — it is never flashed to the session, stored, or audited.
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
