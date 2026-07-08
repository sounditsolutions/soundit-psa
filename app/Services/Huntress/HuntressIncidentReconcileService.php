<?php

namespace App\Services\Huntress;

use App\Enums\AlertSource;
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
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * Reconcile bridged Huntress INCIDENT tickets against the authoritative incident state.
 *
 * When Huntress auto-resolves an incident (→ status `closed`/`dismissed` on the API) it does
 * NOT fire the CW-Manage status webhook, so the bridged PSA ticket is stranded open. This
 * service resolves it — respecting our close conventions, idempotently, and never overriding
 * a ticket a human has taken over.
 *
 * SCOPE — incident tickets only. source=Huntress also covers escalations / ISPM / ITDR /
 * product-notices, which have no incident report. We recognise an incident ticket by parsing
 * "Incident on <host>" from its subject (the ingest format); escalations/notices don't carry
 * it and are skipped entirely.
 *
 * CORRESPONDENCE — resolve ONLY on positive ticket↔incident correspondence:
 *   1. Exact id — the minority of tickets store an incident URL (linked alert source_alert_id
 *      or description); getIncidentReport(id) is definitive.
 *   2. Host + window — the majority store only a synth-hash source_alert_id. We take the
 *      ticket's host (from its subject) and match a closed/dismissed incident whose BODY
 *      mentions that host, with sent_at within the ticket's creation window.
 * Bare time-window matching is NOT a resolve trigger: a coincidental sibling incident close
 * (the ticket's own incident, if still open, is `sent` and excluded) would false-resolve an
 * open ticket a tech still needs. No positive id-or-host correspondence → skip. Lower coverage
 * via skip is the correct trade for a security-status auto-close.
 */
class HuntressIncidentReconcileService
{
    /** How close an incident's sent_at must be to the ticket's created_at to correspond. */
    private const MATCH_WINDOW_MINUTES = 60;

    /** @var array<int, array<int, array<string, mixed>>> org id → closed/dismissed incident rows */
    private array $incidentCache = [];

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
            ->with('client')
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
        $alert = $this->linkedAlert($ticket);

        $status = $this->resolvedIncidentStatusFor($ticket, $alert);
        if ($status === null) {
            return; // not incident-backed, no confident correspondence, or still open — leave it
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
            ? 'Resolved automatically — Huntress dismissed this incident upstream.'
            : 'Resolved automatically — Huntress remediated and closed this incident upstream.';

        // Resolved (not Closed) — mirrors the webhook path's deliberate "Closed → Resolved
        // for human verification" convention (HuntressService::updateTicketFromCw).
        $this->ticketService->changeStatus($fresh, TicketStatus::Resolved, $systemUserId, $note);

        if ($alert) {
            $this->alertService->resolve($alert, 'Resolved via Huntress incident reconcile.');
        }

        $result->updated++;
        $result->details[] = "#{$fresh->id} resolved ({$status})";

        Log::info('[HuntressReconcile] Resolved stranded ticket', [
            'ticket_id' => $fresh->id,
            'incident_status' => $status,
        ]);
    }

    /**
     * The upstream resolution status ('closed'|'dismissed') if we can CONFIDENTLY identify
     * this ticket's incident as resolved, else null.
     */
    private function resolvedIncidentStatusFor(Ticket $ticket, ?Alert $alert): ?string
    {
        // 1. Exact id fast path (the id-bearing minority). An incident URL/id is itself the
        //    positive correspondence and the incident-scope signal.
        $incidentId = $this->extractIncidentId($ticket, $alert);
        if ($incidentId !== null) {
            try {
                $report = $this->client->getIncidentReport($incidentId);
            } catch (\Throwable $e) {
                Log::warning("[HuntressReconcile] getIncidentReport({$incidentId}) failed: {$e->getMessage()}");

                return null;
            }

            return $this->resolvedStatus($report['status'] ?? null);
        }

        // 2. Host + window (the synth-hash majority). SCOPE: a parseable incident host is what
        //    distinguishes an incident ticket from an escalation / ISPM / ITDR / notice.
        $host = $this->ticketIncidentHost($ticket);
        if ($host === null) {
            return null; // not incident-backed → skip
        }

        $orgId = $ticket->client?->huntress_organization_id;
        if ($orgId === null) {
            return null;
        }

        $incident = $this->matchByHost($ticket, (int) $orgId, $host);

        return $incident ? $this->resolvedStatus($incident['status'] ?? null) : null;
    }

    /**
     * The unique closed/dismissed incident whose body mentions the ticket's host within the
     * sent_at window, or null. Requires POSITIVE host correspondence — never a bare time match.
     */
    private function matchByHost(Ticket $ticket, int $orgId, string $host): ?array
    {
        $created = $ticket->created_at;

        $candidates = array_values(array_filter(
            $this->closedIncidentsForOrg($orgId),
            function ($i) use ($created, $host) {
                $sentAt = $i['sent_at'] ?? null;
                if (! $sentAt) {
                    return false;
                }
                if (abs($created->getTimestamp() - Carbon::parse($sentAt)->getTimestamp()) > self::MATCH_WINDOW_MINUTES * 60) {
                    return false;
                }

                return $this->bodyMentionsHost((string) ($i['body'] ?? ''), $host);
            },
        ));

        // Exactly one corresponding incident → confident. Zero or ambiguous → skip.
        return count($candidates) === 1 ? $candidates[0] : null;
    }

    private function bodyMentionsHost(string $body, string $host): bool
    {
        if ($host === '' || $body === '') {
            return false;
        }

        return (bool) preg_match('/\b'.preg_quote($host, '/').'\b/i', $body);
    }

    /** @return array<int, array<string, mixed>> closed/dismissed incident rows for the org */
    private function closedIncidentsForOrg(int $orgId): array
    {
        if (! array_key_exists($orgId, $this->incidentCache)) {
            try {
                $all = $this->client->getIncidentReports(['organization_id' => $orgId]);
            } catch (\Throwable $e) {
                Log::warning("[HuntressReconcile] getIncidentReports(org {$orgId}) failed: {$e->getMessage()}");
                $all = [];
            }

            $this->incidentCache[$orgId] = array_values(array_filter(
                $all,
                fn ($i) => $this->resolvedStatus($i['status'] ?? null) !== null,
            ));
        }

        return $this->incidentCache[$orgId];
    }

    /**
     * The incident host parsed from the ticket subject ("Incident on <host> …", the ingest
     * format). Null for escalations / ISPM / ITDR / notices — which is exactly how we scope
     * this job to incident tickets only.
     */
    private function ticketIncidentHost(Ticket $ticket): ?string
    {
        if ($ticket->subject && preg_match('/Incident\s+on\s+([^\s()]+)/i', $ticket->subject, $m)) {
            $host = trim($m[1]);

            return $host !== '' ? $host : null;
        }

        return null;
    }

    /**
     * Recover an incident report id from the linked alert's source_alert_id (the incident
     * report URL) or a URL in the description. Handles legacy `infection_reports/{id}` and
     * `incident_reports/{id}` forms. Null for the synth-hash majority.
     */
    private function extractIncidentId(Ticket $ticket, ?Alert $alert): ?int
    {
        foreach ([$alert?->source_alert_id, $ticket->description] as $text) {
            if ($text && preg_match('#(?:infection|incident)_reports/(\d+)#', $text, $m)) {
                return (int) $m[1];
            }
        }

        return null;
    }

    /** Normalize a raw incident status to 'closed'|'dismissed', or null if not resolved. */
    private function resolvedStatus(mixed $status): ?string
    {
        $s = strtolower((string) $status);

        return in_array($s, ['closed', 'dismissed'], true) ? $s : null;
    }

    private function linkedAlert(Ticket $ticket): ?Alert
    {
        return Alert::where('source', AlertSource::Huntress->value)
            ->where('ticket_id', $ticket->id)
            ->first();
    }

    /**
     * True if a human has touched the ticket — a non-system-generated note or an EndUser
     * reply. Automation touches (triage / AI technician) use system-generated note types and
     * therefore do NOT count, so genuinely-untended tickets still reconcile.
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

    /** @return array<string> */
    private function openStatusValues(): array
    {
        return array_values(array_map(
            fn (TicketStatus $s) => $s->value,
            array_filter(TicketStatus::cases(), fn (TicketStatus $s) => $s->isOpen()),
        ));
    }
}
