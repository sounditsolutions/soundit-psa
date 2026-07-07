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
 * Reconcile bridged Huntress tickets against the authoritative incident state.
 *
 * The CW-Manage bridge only receives a status write-back when a Huntress remediation is
 * approved/rejected. When Huntress AUTO-resolves an incident (→ status `closed`/`dismissed`
 * on the API) no webhook fires, so the bridged PSA ticket is stranded open. This service
 * polls each open Huntress ticket's incident and resolves it once the incident is
 * closed/dismissed upstream — respecting our close conventions, idempotently, and never
 * overriding a ticket a human has taken over.
 *
 * Matching, in order of confidence:
 *   1. Exact id — the minority of tickets carry an incident URL (in the linked alert's
 *      source_alert_id or the description); getIncidentReport(id) is definitive.
 *   2. List-and-match — the MAJORITY store only a synth-hash source_alert_id (no recoverable
 *      id). We list the org's incident reports and match to the open ticket by
 *      organization_id→client + agent hostname (agent_id→hostname via getAgents, vs the
 *      hostname captured on the linked alert) + sent_at within the ticket's creation window.
 *
 * Safety: acts ONLY on a confident, unique match. Any ambiguity, missing signal, or API
 * failure results in a skip — it never mis-closes a ticket. Driven by our own (small) set of
 * open Huntress tickets, so it stays bounded and naturally client-scoped even though the
 * Huntress account is shared across MSPs.
 */
class HuntressIncidentReconcileService
{
    /** How close an incident's sent_at must be to the ticket's created_at to match. */
    private const MATCH_WINDOW_MINUTES = 60;

    /** @var array<int, array<int, array<string, mixed>>> org id → closed/dismissed incident rows */
    private array $incidentCache = [];

    /** @var array<int, array<int, string>> org id → [agent_id => lowercased hostname] */
    private array $agentCache = [];

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
            return; // no confident resolved match — leave the ticket alone
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
        // 1. Exact id fast path (the id-bearing minority).
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

        // 2. List-and-match (the synth-hash majority) — needs an org mapping.
        $orgId = $ticket->client?->huntress_organization_id;
        if ($orgId === null) {
            return null;
        }

        $incident = $this->matchIncident($ticket, $alert, (int) $orgId);

        return $incident ? $this->resolvedStatus($incident['status'] ?? null) : null;
    }

    /**
     * Find the unique closed/dismissed incident report for this ticket within the org, or null
     * if there is no confident match.
     */
    private function matchIncident(Ticket $ticket, ?Alert $alert, int $orgId): ?array
    {
        $incidents = $this->closedIncidentsForOrg($orgId);
        if (empty($incidents)) {
            return null;
        }

        $created = $ticket->created_at;
        $inWindow = array_values(array_filter($incidents, function ($i) use ($created) {
            $sentAt = $i['sent_at'] ?? null;

            return $sentAt
                && abs($created->getTimestamp() - Carbon::parse($sentAt)->getTimestamp()) <= self::MATCH_WINDOW_MINUTES * 60;
        }));
        if (empty($inWindow)) {
            return null;
        }

        // Disambiguate by agent hostname when we know the ticket's host AND have an agent map.
        // When both are present the host match is authoritative (empty → this host's incident
        // isn't closed yet → skip; >1 → ambiguous → skip).
        $ticketHost = $this->ticketHostname($ticket, $alert);
        if ($ticketHost !== null) {
            $agentHosts = $this->agentHostnamesForOrg($orgId);
            if (! empty($agentHosts)) {
                $byHost = array_values(array_filter(
                    $inWindow,
                    fn ($i) => ($agentHosts[$i['agent_id'] ?? -1] ?? null) === $ticketHost,
                ));

                return count($byHost) === 1 ? $byHost[0] : null;
            }
        }

        // No host / no agent map → rely on window uniqueness (skip if ambiguous).
        return count($inWindow) === 1 ? $inWindow[0] : null;
    }

    /** @return array<int, array<string, mixed>> */
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

    /** @return array<int, string> agent_id → lowercased hostname */
    private function agentHostnamesForOrg(int $orgId): array
    {
        if (! array_key_exists($orgId, $this->agentCache)) {
            $map = [];
            try {
                foreach ($this->client->getAgents(['organization_id' => $orgId]) as $agent) {
                    $host = $agent['hostname'] ?? $agent['host_name'] ?? null;
                    if (isset($agent['id']) && $host) {
                        $map[(int) $agent['id']] = strtolower((string) $host);
                    }
                }
            } catch (\Throwable $e) {
                Log::warning("[HuntressReconcile] getAgents(org {$orgId}) failed: {$e->getMessage()}");
            }
            $this->agentCache[$orgId] = $map;
        }

        return $this->agentCache[$orgId];
    }

    private function ticketHostname(Ticket $ticket, ?Alert $alert): ?string
    {
        $host = $alert?->metadata['agent'] ?? $ticket->primaryAsset()?->hostname;

        return $host ? strtolower((string) $host) : null;
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
