<?php

namespace App\Enums;

/**
 * The persistent state of a per-ticket Technician run (spec §4.4). Approval
 * waits are PERSISTED here (AwaitingApproval), never a sleeping job, and never
 * on the TicketStatus enum (the cockpit derives a badge from this).
 */
enum TechnicianRunState: string
{
    case Gathering = 'gathering';
    case Drafting = 'drafting';
    case AwaitingApproval = 'awaiting_approval';
    case Executing = 'executing';
    case Done = 'done';
    case Denied = 'denied';
    case Superseded = 'superseded';

    /**
     * An approved staged action whose target device was OFFLINE at approval time
     * (bd psa-xr84). Instead of dead-ending, it parks here and auto-runs on the
     * device's next check-in. Terminal outcomes: Done (ran on reconnect), Expired
     * (safety window elapsed → operator re-confirm), Cancelled (operator dropped it).
     */
    case QueuedOffline = 'queued_offline';

    /** A queued_offline action whose safety window elapsed without the device returning; never auto-runs — re-surfaced for explicit re-confirm. */
    case Expired = 'expired';

    /** A queued_offline action the operator cancelled from the cockpit. */
    case Cancelled = 'cancelled';

    /**
     * A held flag_attention notice (Increment H). Distinct from AwaitingApproval
     * because a flag is NOT an executable proposal — it has no gate execution. It
     * is surfaced in its own cockpit lane and resolved by a human acknowledging
     * (→ Done) or dismissing (→ Denied), never executed.
     */
    case Flagged = 'flagged';
}
