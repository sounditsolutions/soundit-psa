<?php

namespace App\Enums;

/**
 * Who moved a ticket's taxonomy node (tickets.category_id).
 *
 * Triage — the coarse triage->taxonomy mapping applied its own resolution
 * (so-0ftg Part 4). Staff — an authenticated user changed it (web UI or a
 * user-attributed API path). System — an unauthenticated, non-triage code
 * path (imports, maintenance commands). Phase 1 treats any non-Triage change
 * that follows a Triage assignment as an override worth learning from.
 */
enum TicketCategoryChangeSource: string
{
    case Triage = 'triage';
    case Staff = 'staff';
    case System = 'system';
}
