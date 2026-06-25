<?php

namespace App\Services\Agent;

use App\Enums\TicketStatus;
use App\Enums\WhoType;
use App\Models\Ticket;
use App\Models\TicketNote;
use App\Support\AgentConfig;

/**
 * The deterministic, model-INDEPENDENT backstop for the propose_close Auto band (CO-19).
 *
 * The agent's `confidence` scalar is model-produced and therefore spoofable by a
 * prompt-injection buried in the ticket text. Before the gate may AUTO-close on
 * confidence, this backstop must INDEPENDENTLY agree using only facts the model
 * cannot fabricate:
 *
 *   1. No recent inbound CLIENT activity — no end-user-authored note (who_type =
 *      EndUser) created within the last AgentConfig::autoQuietDays() days. A client
 *      who just wrote in is, by definition, not done.
 *   2. Not awaiting us — the ticket is not in a state where WE still owe the next
 *      action. Those route to a human even at confidence 1.0.
 *
 * Fail-closed throughout: an unresolved ticket, an unrecognized/awaiting-us status,
 * or any error returns false (never auto). Confidence ALONE can never auto-close.
 */
class CloseAutoEligibility
{
    /**
     * Statuses where it is safe to AUTO-close — the ball is NOT in our court, or
     * the work is already resolved and merely awaiting the close:
     *   - Resolved          → work done; the grace-period auto-close.
     *   - PendingClient     → we're waiting on the client (the classic stale ghost).
     *   - PendingThirdParty → we're waiting on a vendor (stale, blocked on others).
     *
     * Deliberately EXCLUDED (we still owe the next move → human eyes, even at 1.0):
     *   - New, InProgress   → awaiting US.
     *   - Closed            → already closed (never proposed); not a safe auto target.
     *
     * Expressed as an allow-list so any future/unknown status is excluded too —
     * fail-closed by construction (the safety boundary biases toward NOT auto-closing).
     *
     * @var list<TicketStatus>
     */
    private const AUTO_SAFE_STATUSES = [
        TicketStatus::Resolved,
        TicketStatus::PendingClient,
        TicketStatus::PendingThirdParty,
    ];

    public static function eligible(Ticket $ticket): bool
    {
        try {
            $status = $ticket->status;

            // Awaiting us, already closed, or an undeterminable status → never auto.
            if (! $status instanceof TicketStatus || ! in_array($status, self::AUTO_SAFE_STATUSES, true)) {
                return false;
            }

            return ! self::hasRecentInboundClientNote($ticket);
        } catch (\Throwable) {
            // Any signal we cannot determine → fail closed.
            return false;
        }
    }

    /**
     * True iff a client/end-user-authored note was created within the quiet window.
     *
     * Counts soft-deleted notes (withTrashed): a recently-deleted client reply is
     * still evidence the client engaged — the fail-closed reading. Reads created_at
     * (the row-write time), not the user-settable noted_at, so the signal cannot be
     * backdated out of the window.
     */
    private static function hasRecentInboundClientNote(Ticket $ticket): bool
    {
        $cutoff = now()->subDays(AgentConfig::autoQuietDays());

        return TicketNote::withTrashed()
            ->where('ticket_id', $ticket->id)
            ->where('who_type', WhoType::EndUser->value)
            ->where('created_at', '>=', $cutoff)
            ->exists();
    }
}
