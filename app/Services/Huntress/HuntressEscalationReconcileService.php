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
 * Reconcile bridged Huntress ESCALATION tickets against the authoritative escalation state.
 *
 * When a Huntress escalation resolves upstream (status → `resolved` / `resolved_at` set) it
 * does NOT fire the CW-Manage status webhook, so the bridged PSA ticket is stranded open —
 * exactly the incident-side gap that {@see HuntressIncidentReconcileService} closes, but for
 * the escalation class (integration-health "Failed to Deliver", EDR/M365 control escalations,
 * …). This service resolves the ticket — respecting our close conventions, idempotently, and
 * never overriding a ticket a human has taken over.
 *
 * SCOPE — escalation tickets only. source=Huntress also covers incidents (handled by the
 * incident reconciler) and product-notices. We recognise an escalation ticket by an
 * "Escalation" marker in its subject (the ingest format, e.g. "Huntress High Escalation |
 * Failed to Deliver") AND the ABSENCE of the incident marker "Incident on <host>" — so the
 * two reconcilers never touch the same ticket.
 *
 * CORRESPONDENCE — resolve ONLY on positive ticket↔escalation correspondence:
 *   1. Exact id — an escalation id captured at ingest (alert metadata `escalation_id`) or an
 *      `escalations/{id}` URL in the linked alert / description. getEscalation(id) is
 *      definitive. This is the clean path for tickets ingested after the id-capture fix.
 *   2. Org + subject + window, UNIQUELY — the legacy path for org-associated escalation
 *      tickets with no stored id. We take the ticket's mapped org and its subject "core",
 *      and require exactly one escalation (across ALL statuses) for that org whose subject
 *      corresponds within the creation window; if that unique escalation is resolved, we
 *      resolve the ticket. Requiring uniqueness ACROSS statuses means a ticket whose own
 *      escalation is still open (also in the window) is ambiguous → skipped, never
 *      false-resolved by a coincidental sibling close.
 *
 * Bare time-window matching is NOT a resolve trigger. Account-level escalations (empty
 * organizations[], e.g. "Failed to Deliver") carry no org to scope on and no recoverable id,
 * so — absent an ingest-captured id — they are skipped (manual closure is the correct
 * fallback). Lower coverage via skip is the right trade for a security-status auto-close.
 */
class HuntressEscalationReconcileService
{
    /** How close an escalation's created_at must be to the ticket's created_at to correspond. */
    private const MATCH_WINDOW_MINUTES = 60;

    /** @var array<int, array<int, array<string, mixed>>> org id → all escalation rows for that org */
    private array $escalationCache = [];

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
                Log::error("[HuntressEscalationReconcile] Ticket #{$ticket->id}: {$e->getMessage()}");
                $result->recordError("Ticket #{$ticket->id}: {$e->getMessage()}");
            }
        }

        return $result;
    }

    private function reconcileTicket(Ticket $ticket, SyncResult $result): void
    {
        if (! $this->isEscalationTicket($ticket)) {
            return; // incident / product-notice — not ours to touch
        }

        $alert = $this->linkedAlert($ticket);

        if (! $this->escalationResolvedFor($ticket, $alert)) {
            return; // no confident correspondence, or the escalation is still open — leave it
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

        // Resolved (not Closed) — mirrors the webhook path's deliberate "Closed → Resolved
        // for human verification" convention (HuntressService::updateTicketFromCw).
        $this->ticketService->changeStatus(
            $fresh,
            TicketStatus::Resolved,
            $systemUserId,
            'Resolved automatically — Huntress resolved this escalation upstream.',
        );

        if ($alert) {
            $this->alertService->resolve($alert, 'Resolved via Huntress escalation reconcile.');
        }

        $result->updated++;
        $result->details[] = "#{$fresh->id} resolved";

        Log::info('[HuntressEscalationReconcile] Resolved stranded escalation ticket', [
            'ticket_id' => $fresh->id,
        ]);
    }

    /**
     * True if we can CONFIDENTLY identify this ticket's escalation as resolved upstream.
     */
    private function escalationResolvedFor(Ticket $ticket, ?Alert $alert): bool
    {
        // 1. Exact id fast path (the id-bearing tickets — clean path after the ingest fix).
        //    An escalation id is itself the positive correspondence.
        $escalationId = $this->extractEscalationId($ticket, $alert);
        if ($escalationId !== null) {
            try {
                $escalation = $this->client->getEscalation($escalationId);
            } catch (\Throwable $e) {
                Log::warning("[HuntressEscalationReconcile] getEscalation({$escalationId}) failed: {$e->getMessage()}");

                return false;
            }

            return $this->isResolved($escalation);
        }

        // 2. Org + subject + window, uniquely (the legacy no-id path). SCOPE: requires the
        //    ticket's client to be org-mapped AND the escalation to carry that org — which
        //    excludes account-level escalations (e.g. "Failed to Deliver") entirely.
        $orgId = $ticket->client?->huntress_organization_id;
        if ($orgId === null) {
            return false;
        }

        $core = $this->ticketSubjectCore($ticket);
        if ($core === '') {
            return false;
        }

        return $this->matchByOrgAndSubject($ticket, (int) $orgId, $core);
    }

    /**
     * True when exactly one escalation for the org corresponds to the ticket (subject core +
     * creation window) across ALL statuses, and that escalation is resolved. Uniqueness across
     * statuses is the guard against a coincidental sibling-close false-resolving a ticket whose
     * own escalation is still open.
     */
    private function matchByOrgAndSubject(Ticket $ticket, int $orgId, string $core): bool
    {
        $created = $ticket->created_at;

        $candidates = array_values(array_filter(
            $this->escalationsForOrg($orgId),
            function ($e) use ($created, $core, $orgId) {
                if (! in_array($orgId, $this->escalationOrgIds($e), true)) {
                    return false;
                }

                $createdAt = $e['created_at'] ?? null;
                if (! $createdAt) {
                    return false;
                }
                if (abs($created->getTimestamp() - Carbon::parse($createdAt)->getTimestamp()) > self::MATCH_WINDOW_MINUTES * 60) {
                    return false;
                }

                return $this->subjectsCorrespond($core, (string) ($e['subject'] ?? ''));
            },
        ));

        // Exactly one corresponding escalation → confident. Zero or ambiguous → skip.
        if (count($candidates) !== 1) {
            return false;
        }

        return $this->isResolved($candidates[0]);
    }

    /**
     * Positive subject correspondence: the ticket's subject "core" and the escalation's
     * subject share containment after normalisation. Not a bare time-window match — the
     * escalation subject must be present, non-trivial, and correspond.
     */
    private function subjectsCorrespond(string $core, string $escalationSubject): bool
    {
        $a = $this->normalizeSubject($core);
        $b = $this->normalizeSubject($escalationSubject);

        if ($a === '' || $b === '') {
            return false;
        }

        return str_contains($a, $b) || str_contains($b, $a);
    }

    /**
     * The escalation "core" from the ticket subject: the text after the "Escalation |"
     * separator (the ingest format "Huntress <product> <sev> Escalation | <core>"), or the
     * whole subject when no separator is present. Normalisation happens in subjectsCorrespond.
     */
    private function ticketSubjectCore(Ticket $ticket): string
    {
        $subject = (string) $ticket->subject;

        if (str_contains($subject, '|')) {
            $parts = explode('|', $subject);

            return trim(end($parts));
        }

        return trim($subject);
    }

    private function normalizeSubject(string $value): string
    {
        $lower = mb_strtolower($value);
        $collapsed = preg_replace('/[^a-z0-9]+/', ' ', $lower);

        return trim((string) $collapsed);
    }

    /** @return array<int, array<string, mixed>> all escalation rows for the org (cached) */
    private function escalationsForOrg(int $orgId): array
    {
        if (! array_key_exists($orgId, $this->escalationCache)) {
            try {
                $this->escalationCache[$orgId] = $this->client->getEscalations(['organization_id' => $orgId]);
            } catch (\Throwable $e) {
                Log::warning("[HuntressEscalationReconcile] getEscalations(org {$orgId}) failed: {$e->getMessage()}");
                $this->escalationCache[$orgId] = [];
            }
        }

        return $this->escalationCache[$orgId];
    }

    /**
     * The org ids an escalation touches. organizations[] elements are org objects ({id,…}) or
     * bare ids; an empty array means account-level (no org association).
     *
     * @param  array<string, mixed>  $escalation
     * @return array<int, int>
     */
    private function escalationOrgIds(array $escalation): array
    {
        $ids = [];
        foreach ((array) ($escalation['organizations'] ?? []) as $org) {
            $id = is_array($org) ? ($org['id'] ?? null) : $org;
            if (is_numeric($id) && (int) $id > 0) {
                $ids[] = (int) $id;
            }
        }

        return $ids;
    }

    /**
     * An escalation is resolved upstream when its status is `resolved` or it carries a
     * `resolved_at` timestamp. (Status enum: open/sent = open, resolved = handled.)
     *
     * @param  array<string, mixed>  $escalation
     */
    private function isResolved(array $escalation): bool
    {
        if (! empty($escalation['resolved_at'])) {
            return true;
        }

        return strtolower((string) ($escalation['status'] ?? '')) === 'resolved';
    }

    /**
     * Recover an escalation id from the linked alert's stored `escalation_id` metadata (the
     * ingest-fix clean path) or an `escalations/{id}` URL in the alert source_alert_id or the
     * ticket description. Null when no id is recoverable (the legacy no-id majority).
     */
    private function extractEscalationId(Ticket $ticket, ?Alert $alert): ?int
    {
        $metaId = $alert?->metadata['escalation_id'] ?? null;
        if (is_numeric($metaId) && (int) $metaId > 0) {
            return (int) $metaId;
        }

        foreach ([$alert?->source_alert_id, $ticket->description] as $text) {
            if ($text && preg_match('#escalations/(\d+)#', $text, $m)) {
                return (int) $m[1];
            }
        }

        return null;
    }

    /**
     * True if this is an escalation-backed Huntress ticket: its subject carries the
     * "Escalation" marker and NOT the incident "Incident on <host>" marker (which is the
     * incident reconciler's scope). Product-notices carry neither and are skipped.
     */
    private function isEscalationTicket(Ticket $ticket): bool
    {
        $subject = (string) $ticket->subject;

        if (preg_match('/Incident\s+on\s+\S/i', $subject)) {
            return false;
        }

        return (bool) preg_match('/\bEscalation\b/i', $subject);
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
