<?php

namespace App\Services\Huntress;

use App\Enums\AlertSource;
use App\Enums\AlertStatus;
use App\Enums\NoteType;
use App\Enums\TicketSource;
use App\Enums\TicketStatus;
use App\Enums\WhoType;
use App\Models\Alert;
use App\Models\Ticket;
use App\Models\TicketNote;
use App\Services\AlertService;
use App\Services\SyncResult;
use App\Services\TicketService;
use App\Support\HuntressConfig;
use Illuminate\Support\Facades\Log;

/**
 * Reconcile bridged Huntress tickets against the authoritative incident state.
 *
 * The CW-Manage bridge only receives a status write-back when a Huntress remediation is
 * approved/rejected. When Huntress AUTO-resolves an incident (→ status `closed`/`dismissed`
 * on the API) no webhook fires, so the bridged PSA ticket is stranded open. This service
 * polls each open Huntress ticket's incident report and resolves the ticket once the
 * incident is closed/dismissed upstream — respecting our close conventions, idempotently,
 * and never overriding a ticket a human has taken over.
 *
 * Driven by our own (small) set of open Huntress tickets, so it stays bounded and naturally
 * client-scoped even though the Huntress account is shared across MSPs.
 */
class HuntressIncidentReconcileService
{
    public function __construct(
        private readonly HuntressClient $client,
        private readonly TicketService $ticketService,
        private readonly AlertService $alertService,
    ) {}

    public function reconcile(): SyncResult
    {
        $result = new SyncResult;

        $tickets = Ticket::where('source', TicketSource::Huntress->value)
            ->whereIn('status', $this->openStatusValues())
            ->get();

        foreach ($tickets as $ticket) {
            try {
                $this->reconcileTicket($ticket, $result);
            } catch (\Throwable $e) {
                Log::error("[HuntressReconcile] Ticket #{$ticket->id}: {$e->getMessage()}");
                $result->recordError("Ticket #{$ticket->id}: {$e->getMessage()}");
            }
        }

        return $result;
    }

    private function reconcileTicket(Ticket $ticket, SyncResult $result): void
    {
        $incidentId = $this->incidentIdForTicket($ticket);
        if ($incidentId === null) {
            $result->details[] = "#{$ticket->id} skipped: no incident report id";

            return;
        }

        $report = $this->client->getIncidentReport($incidentId);
        $status = strtolower((string) ($report['status'] ?? ''));

        // Only definitive upstream resolution acts; `sent` (still open) / unknown → no-op.
        if (! in_array($status, ['closed', 'dismissed'], true)) {
            return;
        }

        // Re-fetch and guard on the CURRENT row: idempotency (already resolved/closed) and
        // human takeover — a human who re-opened or engaged the ticket owns closing it.
        $fresh = $ticket->fresh();
        if ($fresh === null || ! $fresh->status->isOpen()) {
            return;
        }
        if ($this->hasHumanTouch($fresh)) {
            $result->details[] = "#{$fresh->id} skipped: human-touched";

            return;
        }

        $systemUserId = HuntressConfig::systemUserId();
        if (! $systemUserId) {
            throw new \RuntimeException('Huntress system user not configured and no users exist in the system');
        }

        $note = $status === 'dismissed'
            ? "Resolved automatically — Huntress dismissed this incident upstream (incident #{$incidentId})."
            : "Resolved automatically — Huntress remediated and closed this incident upstream (incident #{$incidentId}).";

        // Resolved (not Closed) — mirrors the webhook path's deliberate "Closed → Resolved
        // for human verification" convention (HuntressService::updateTicketFromCw).
        $this->ticketService->changeStatus($fresh, TicketStatus::Resolved, $systemUserId, $note);

        $this->resolveLinkedAlert($fresh);

        $result->updated++;
        $result->details[] = "#{$fresh->id} resolved ({$status}, incident #{$incidentId})";

        Log::info('[HuntressReconcile] Resolved stranded ticket', [
            'ticket_id' => $fresh->id,
            'incident_id' => $incidentId,
            'incident_status' => $status,
        ]);
    }

    /**
     * Resolve the incident report id from the linked Huntress alert's source_alert_id
     * (which IS the incident report URL), falling back to a URL in the ticket description.
     * Handles both legacy `infection_reports/{id}` and `incident_reports/{id}` URL forms.
     */
    private function incidentIdForTicket(Ticket $ticket): ?int
    {
        $alert = Alert::where('source', AlertSource::Huntress->value)
            ->where('ticket_id', $ticket->id)
            ->first();

        foreach ([$alert?->source_alert_id, $ticket->description] as $text) {
            if ($text && preg_match('#(?:infection|incident)_reports/(\d+)#', $text, $m)) {
                return (int) $m[1];
            }
        }

        return null;
    }

    /**
     * True if a human has touched the ticket — a non-system-generated note or an EndUser
     * reply. Automation touches (triage / AI technician) use system-generated note types
     * and therefore do NOT count, so genuinely-untended tickets still reconcile.
     */
    private function hasHumanTouch(Ticket $ticket): bool
    {
        $systemTypes = array_map(fn (NoteType $t) => $t->value, NoteType::systemGenerated());

        return TicketNote::where('ticket_id', $ticket->id)
            ->where(function ($q) use ($systemTypes) {
                $q->whereNotIn('note_type', $systemTypes)
                    ->orWhere('who_type', WhoType::EndUser->value);
            })
            ->exists();
    }

    private function resolveLinkedAlert(Ticket $ticket): void
    {
        $alert = Alert::where('source', AlertSource::Huntress->value)
            ->where('ticket_id', $ticket->id)
            ->whereIn('status', [AlertStatus::Active, AlertStatus::Acknowledged, AlertStatus::Ticketed])
            ->first();

        if ($alert) {
            $this->alertService->resolve($alert, 'Resolved via Huntress incident reconcile.');
        }
    }

    /** @return array<string> */
    private function openStatusValues(): array
    {
        return array_values(array_map(
            fn (TicketStatus $s) => $s->value,
            array_filter(TicketStatus::cases(), fn (TicketStatus $s) => $s->isOpen()),
        ));
    }
}
