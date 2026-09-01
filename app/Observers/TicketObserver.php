<?php

namespace App\Observers;

use App\Enums\TicketSource;
use App\Enums\TicketStatus;
use App\Jobs\GenerateTicketResolution;
use App\Jobs\MineTicketKnowledge;
use App\Jobs\RunTechnicianLoop;
use App\Jobs\RunTriagePipeline;
use App\Jobs\SendT2TCallback;
use App\Models\TechnicianRun;
use App\Models\Ticket;
use App\Models\TicketCategoryChangeLog;
use App\Models\TicketDescriptionChangeLog;
use App\Services\NotificationService;
use App\Services\Signals\SignalHub;
use App\Support\T2TConfig;
use App\Support\TechnicianConfig;
use App\Support\TriageConfig;
use App\Support\WikiConfig;
use Illuminate\Support\Facades\Log;

class TicketObserver
{
    /**
     * Ownership stamp for a category chosen AT creation (so-0ftg CREATE path,
     * psa-begf3). Mirrors updating(): whoever sets category_id gets written
     * into tickets.category_source in the SAME INSERT, so a human/agent create
     * is honestly attributed (Staff / System, from execution context — never
     * caller-supplied, so it cannot be forged) and stays triage-protected. Only
     * a real node is stamped: a null category at create is the absence of a
     * choice, not a deliberate clear, so it carries no ownership (leaving triage
     * free to map it later).
     */
    public function creating(Ticket $ticket): void
    {
        if (filled($ticket->category_id)) {
            $ticket->category_source = TicketCategoryChangeLog::attributionSource();
        }
    }

    /**
     * Notify technicians and auto-dispatch triage when a new ticket is created.
     */
    public function created(Ticket $ticket): void
    {
        // Taxonomy audit for a category set AT creation (so-0ftg CREATE path):
        // mirrors updated()'s change log — same seam, so every create surface
        // (web form, MCP create_ticket, imports) is captured without opting in.
        // previous is null (there was no prior node). AUDIT-ONLY and never lets
        // a broken log write take the ticket creation down with it, but screams.
        if (filled($ticket->category_id)) {
            try {
                TicketCategoryChangeLog::recordFor($ticket);
            } catch (\Throwable $e) {
                Log::error('[TicketObserver] Failed to record create category change log', [
                    'ticket_id' => $ticket->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        try {
            app(SignalHub::class)->emit('ticket.created', $ticket, "Ticket #{$ticket->id} created", [
                'client_id' => $ticket->client_id,
                'priority' => $ticket->priority_order,
            ]);
        } catch (\Throwable $e) {
            Log::warning('[TicketObserver] Failed to emit ticket.created signal', [
                'ticket_id' => $ticket->id,
                'error' => $e->getMessage(),
            ]);
        }

        app(NotificationService::class)->notifyTicketCreated($ticket);

        // Recursion guard: skip AI dispatches for tickets created by the system/AI-actor user.
        $isSystemCreated = $ticket->created_by && $ticket->created_by === TriageConfig::systemUserId();

        if (! $isSystemCreated) {
            if (TriageConfig::autoTriageEnabled()) {
                RunTriagePipeline::dispatch($ticket->id, 'triage');
            }

            // AI Technician Loop (spec §4.1) — same system-user recursion guard as triage.
            // Gated by TechnicianConfig::enabled().
            if (TechnicianConfig::enabled()) {
                RunTechnicianLoop::dispatch($ticket->id);
            }
        }
    }

    /**
     * Ownership stamp (psa-trjwf re-review): whoever is changing category_id
     * gets written into tickets.category_source in the SAME UPDATE statement
     * — updating() fires pre-persist, so mutating the model here rides the
     * one atomic write. The change-log INSERT below (updated()) cannot carry
     * this: it trails the row update, so a concurrent triage transaction
     * holding the row lock could see a human's fresh category_id while the
     * Staff log row was still pending, misread ownership from the stale log,
     * and clobber the human's choice. Unconditional assignment: attribution
     * comes from execution context (runAsTriage flag / auth), never from
     * caller-supplied attributes, so it cannot be forged.
     */
    public function updating(Ticket $ticket): void
    {
        if ($ticket->isDirty('category_id')) {
            $ticket->category_source = TicketCategoryChangeLog::attributionSource();
        }

        // #992: a description rewrite must also drop the pre-rendered HTML the
        // email ingest stored (EmailService sets tickets.description_html), or
        // the edit is a SILENT NO-OP on exactly the tickets most likely to need
        // one. getRenderedDescriptionAttribute() prefers description_html
        // whenever it is non-null and only falls back to rendering the
        // description markdown when it is null — so leaving the column set
        // means the page, and the client, keep seeing the old text.
        //
        // Nulling it here rather than in a controller or service is the same
        // choice as the category_source stamp above: updating() fires
        // pre-persist, so this rides the ONE atomic UPDATE, and every writer
        // (web form, MCP update_ticket, jobs) is covered without opting in.
        //
        // Guarded on description_html NOT being dirty so a writer that
        // deliberately supplies both — the email pipeline, an importer — keeps
        // the HTML it just set. Cost, accepted deliberately: an edited
        // email-sourced description loses the original rich rendering,
        // including any inline images, and renders from the staff-supplied
        // markdown instead. That is the point of the edit; the log row keeps a
        // full copy of the replaced HTML (previous_description_html), so the
        // clear is a supersession with provenance rather than an unrecorded
        // destruction, and non-inline attachments are stored separately and
        // are unaffected.
        if ($ticket->isDirty('description') && ! $ticket->isDirty('description_html')) {
            $ticket->description_html = null;
        }
    }

    public function updated(Ticket $ticket): void
    {
        // Taxonomy change log (so-0ftg Part 4): every tickets.category_id move
        // is recorded here — the one seam ALL writers pass through (triage
        // mapping, web UI, future MCP tools) — so Phase-1 mapping refinement
        // gets its override data without each writer opting in. AUDIT-ONLY:
        // this INSERT trails the row update, so ownership decisions read the
        // category_source column stamped in updating(), never this table.
        // Never lets a broken log write take a ticket save down with it, but
        // screams.
        if ($ticket->wasChanged('category_id')) {
            try {
                TicketCategoryChangeLog::recordFor($ticket);
            } catch (\Throwable $e) {
                Log::error('[TicketObserver] Failed to record category change log', [
                    'ticket_id' => $ticket->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        // Description audit (#992, Jeeves's audit-seam ruling 2026-09-01): a
        // client-visible field rewrite must leave an equivalent record from
        // EVERY surface, so it is logged at the model seam rather than in the
        // web controller — the MCP path's technician_action_logs row stays as
        // it is and answers a different question ("what did the AI technician
        // do") than this one ("how did this field change").
        //
        // Keyed on description ALONE, so the description_html null written in
        // updating() cannot double-fire this: one edit, one row. AUDIT-ONLY and
        // never lets a broken log write take the ticket save down with it, but
        // screams.
        if ($ticket->wasChanged('description')) {
            try {
                TicketDescriptionChangeLog::recordFor($ticket);
            } catch (\Throwable $e) {
                Log::error('[TicketObserver] Failed to record description change log', [
                    'ticket_id' => $ticket->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        // T2T callback — only for HelpdeskButton tickets, on a status change.
        if ($ticket->source === TicketSource::HelpdeskButton && $ticket->wasChanged('status')) {
            $callbackUrl = T2TConfig::get('callback_url');

            if ($callbackUrl) {
                SendT2TCallback::dispatch($ticket->id, $callbackUrl);
            }
        }

        // Wiki mining (spec §5.1 trigger 2; mining-coverage Decision 3): fire when the ticket
        // reaches a terminal state (Resolved or Closed) with a resolution, OR when the
        // resolution is added/edited while already terminal (resolve-first-write-later).
        // Idempotency (hash on resolution text) means a later auto-close does not re-mine,
        // and editing the resolution does re-mine (captures the correction).
        $isTerminal = in_array($ticket->status, [TicketStatus::Resolved, TicketStatus::Closed], true);
        $becameTerminalOrResolutionChanged = $ticket->wasChanged('status') || $ticket->wasChanged('resolution');

        if (
            $isTerminal
            && $becameTerminalOrResolutionChanged
            && filled($ticket->resolution)
            && WikiConfig::autoMineEnabled()
        ) {
            MineTicketKnowledge::dispatch($ticket->id);
        }

        // Auto-fallback: if a ticket reaches a terminal state with NO resolution, queue a job
        // to AI-draft one so the wiki mining loop always has something to mine (spec §T4).
        // Keyed on wasChanged('status') — the status *transition* — so when the job later
        // writes `resolution` (status unchanged), this branch does NOT re-fire → no loop.
        // The mining branch above uses wasChanged('resolution'), so it DOES fire on that save.
        if (
            $isTerminal
            && $ticket->wasChanged('status')
            && empty($ticket->resolution)
            && WikiConfig::autoMineEnabled()
        ) {
            GenerateTicketResolution::dispatch($ticket->id);
        }

        // Auto-withdraw held close proposals when the ticket is Closed by anyone
        // (psa-y4ft, part 3): a pending propose_close becomes moot the moment its
        // ticket is Closed — withdraw it so no redundant proposal lingers for a
        // human to dismiss by hand. Scoped to awaiting_approval, so an in-flight
        // approval (which claims its run to Executing before closing) is never
        // clobbered. Held-safe and always-on: it only ever removes a now-moot
        // proposal, never closes anything.
        if ($ticket->wasChanged('status') && $ticket->status === TicketStatus::Closed) {
            TechnicianRun::withdrawHeldClosesForClosedTicket($ticket->id);
        }
    }
}
