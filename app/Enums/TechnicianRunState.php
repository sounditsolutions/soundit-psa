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
}
