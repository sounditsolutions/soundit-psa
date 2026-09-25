<?php

namespace App\Services\Technician\Scheduled;

use App\Models\McpToken;
use App\Models\TechnicianRun;
use App\Models\Ticket;
use App\Models\User;
use InvalidArgumentException;

final class ScheduledPolicy
{
    public const MAX_TRANSPORT_SECONDS = 610;

    public const RECEIPT_GRACE_SECONDS = 30;

    public const OVERLAP_LOCK = 'scheduled-approvals:sweep-drain';

    // Explicit bounded lease: only the database store substitutes a default expiry, so
    // on file/redis/memcached a SIGKILLed holder would otherwise block recovery, note
    // delivery and the drain forever. release() remains owner-checked, so an expired
    // lease is never force-released by a peer. Size alone can NEVER make the lease
    // "longer than any run": 100 rows each bounded at MAX_TRANSPORT_SECONDS outlast any
    // sane expiry, so holders bound their own lock-held work below instead.
    public const OVERLAP_LOCK_SECONDS = 3600;

    /**
     * Latest elapsed second at which a lock holder may START another unit of work. The
     * reserve is twice the per-unit worst case (one bounded transport plus its receipt
     * grace), covering the in-flight unit and the local recovery/evidence reads around
     * it, so a live holder finishes inside OVERLAP_LOCK_SECONDS however long its queue
     * is and no second sweep or drain can run against its in-flight rows.
     */
    public const OVERLAP_WORK_SECONDS = self::OVERLAP_LOCK_SECONDS - 2 * (self::MAX_TRANSPORT_SECONDS + self::RECEIPT_GRACE_SECONDS);

    /**
     * Slice of that budget reserved for note delivery, which is local database work with
     * no vendor transport. Dispatch may spend everything up to DISPATCH_WORK_SECONDS but
     * never this remainder: a backlog slow enough to exhaust the dispatch share is exactly
     * when the operator most needs the uncertain/blocked notes that backlog generates.
     */
    public const NOTE_WORK_SECONDS = self::MAX_TRANSPORT_SECONDS + self::RECEIPT_GRACE_SECONDS + 300;

    /** Latest elapsed second at which a sweep may START dispatching another row. */
    public const DISPATCH_WORK_SECONDS = self::OVERLAP_WORK_SECONDS - self::NOTE_WORK_SECONDS;

    public function approver(int $id): User
    {
        $user = User::find($id);
        if (! $user || ! $user->is_active || ! ($user->isAdmin() || $user->isTech())) {
            throw new InvalidArgumentException('approver_revoked');
        }

        return $user;
    }

    /**
     * @param  ScheduledApprover|null  $approver  who authorised the row. NULL preserves the
     *                                            human-approved reading for the pre-transaction
     *                                            call sites that have no row yet; a token-approved
     *                                            admission passes ScheduledApprover::token(), and
     *                                            the fire-time preflight rebuilds it from the row's
     *                                            own approver_user_id so the strict check cannot be
     *                                            skipped by a caller that simply forgot to ask.
     */
    public function lineage(TechnicianRun $run, ?int $tokenId, ?ScheduledApprover $approver = null): void
    {
        // No legacy token labels or caller-supplied IDs are grandfathered. Staging
        // instrumentation must persist this provenance before adapters can enroll.
        $provenance = $run->proposed_meta['scheduled_provenance'] ?? null;
        if (! is_array($provenance) || ($provenance['version'] ?? null) !== 1) {
            throw new InvalidArgumentException('provenance_missing');
        }
        if ($tokenId === null) {
            if (($provenance['kind'] ?? null) !== 'native_human' || ! is_int($provenance['user_id'] ?? null)) {
                throw new InvalidArgumentException('provenance_missing');
            }
            $this->approver($provenance['user_id']);

            return;
        }
        if (($provenance['kind'] ?? null) !== 'mcp' || ($provenance['token_id'] ?? null) !== $tokenId) {
            throw new InvalidArgumentException('lineage_mismatch');
        }
        $token = McpToken::find($tokenId);
        $tool = ActionRegistry::directTool($run->action_type);
        // A human-approved row needs the token to still hold the capability in EITHER
        // mode: `:immediate` implies staged in the grant grammar (McpToolModes), so a
        // token that could have run the tool now is not refused the safer approved path.
        // Deliberately no null/full-surface fallback and no bare-name grandfathering.
        //
        // A TOKEN-approved row (ruled design point 3) is the stricter case: no human ever
        // saw it, so the token's own `<tool>:immediate` grant is the entire authority and
        // must STILL be held at fire time. A downgrade to `:staged`, a pause or a revoke
        // between admission and the window refuses here with the same named reason —
        // withdrawing the grant is how an operator stops a queued token action. The
        // approver-mode branch is read from the row, never from a caller's argument, so a
        // token row can never be checked under the looser human rule.
        $modes = $approver?->isToken() ? [$tool.':immediate'] : [$tool.':staged', $tool.':immediate'];
        if (! $token || ! $token->isActive() || ! is_array($token->tools)
            || array_intersect($modes, $token->tools) === []) {
            throw new InvalidArgumentException('lineage_revoked');
        }
        // A token-approved row must also still be the same token that queued it: the
        // lineage_mismatch check above compares the provenance to $tokenId, and the
        // approver carries that same id, so a row whose columns disagree is refused.
        if ($approver?->isToken() && $approver->tokenId !== $tokenId) {
            throw new InvalidArgumentException('lineage_mismatch');
        }
    }

    public function ticket(TechnicianRun $run): void
    {
        $ticket = Ticket::automationVisible()->find($run->ticket_id);
        if (! $ticket || (int) $ticket->client_id !== (int) $run->client_id || ! $run->client_id) {
            throw new InvalidArgumentException('ticket_binding_changed');
        }
    }
}
