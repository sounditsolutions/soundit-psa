<?php

namespace App\Enums;

/**
 * Who rewrote a ticket's description (tickets.description).
 *
 * Staff — an authenticated user changed it (the staff web UI, or any
 * user-attributed API path). System — a non-interactive writer with no auth
 * context: the email ingest pipeline, queued jobs, imports, maintenance
 * commands, and the MCP executors when they run outside a user session.
 *
 * Deliberately NOT reusing TicketCategoryChangeSource: that enum carries a
 * Triage case that can never apply to a description, and its semantics are
 * taxonomy-specific (#992 audit-seam ruling, 2026-09-01).
 */
enum TicketDescriptionChangeSource: string
{
    case Staff = 'staff';
    case System = 'system';
}
