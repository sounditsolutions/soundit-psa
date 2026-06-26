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
     * A held flag_attention notice (Increment H). Distinct from AwaitingApproval
     * because a flag is NOT an executable proposal — it has no gate execution. It
     * is surfaced in its own cockpit lane and resolved by a human acknowledging
     * (→ Done) or dismissing (→ Denied), never executed.
     */
    case Flagged = 'flagged';
}
