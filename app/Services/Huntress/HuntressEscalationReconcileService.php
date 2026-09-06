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
 *      definitive AS TO EXISTENCE, and says NOTHING about OWNERSHIP. Every id we can recover
 *      traces back to text we did not author — `source_alert_id` and the ticket description
 *      are free text that can quote any tenant's escalation URL, and the alert metadata id is
 *      scraped by HuntressService out of the vendor payload body with an unanchored pattern.
 *      So a fetched escalation must ALSO show it carries the ticket client's mapped org, with
 *      no exemption for how the id arrived, and fails closed when correspondence cannot be
 *      established (no client org mapping, or a payload with no readable organizations[]).
 *      The single exception is an escalation with `organizations` present and empty: that is
 *      the account-level shape, which is associated with no org by definition and is the one
 *      class of escalation path 2 below structurally cannot reach. This is the clean path for
 *      tickets ingested after the id-capture fix.
 *   2. Org + subject + window, UNIQUELY — the legacy path for org-associated escalation
 *      tickets with NO stored id. We take the ticket's mapped org and its subject "core",
 *      and require exactly one escalation (across ALL statuses) for that org whose subject
 *      corresponds within the creation window; if that unique escalation is resolved, we
 *      resolve the ticket. Requiring uniqueness ACROSS statuses means a ticket whose own
 *      escalation is still open (also in the window) is ambiguous → skipped, never
 *      false-resolved by a coincidental sibling close. That guard holds only because the
 *      ticket's own escalation is presumed to BE one of the org's rows — so a ticket whose
 *      stored id 404s is skipped, never matched here. Note what the 404 does and does not
 *      establish: it is NOT proof the escalation was deleted, and NOT proof it is absent from
 *      the org listing. HuntressClient maps any upstream 404 to the same code, so a narrowed
 *      key scope, a changed base path or a gateway yields one while the escalation is alive
 *      and possibly still open. The weaker claim is the one we rely on, and it is sufficient:
 *      once the by-id read fails we can no longer confirm WHICH row is this ticket's, so
 *      uniqueness stops implying ownership and a lone survivor may be a sibling.
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
            // Only the FETCH is guarded. Classifying a successfully-fetched escalation stays
            // outside the catch so that anything isResolved() raises is not reported as a
            // failed HTTP call — that error accounting is the pre-change behaviour and is kept.
            try {
                $escalation = $this->client->getEscalation($escalationId);
            } catch (\Throwable $e) {
                if (! ($e instanceof HuntressClientException) || $e->getCode() !== 404) {
                    Log::warning("[HuntressEscalationReconcile] getEscalation({$escalationId}) failed: {$e->getMessage()}", [
                        'ticket_id' => $ticket->id,
                    ]);

                    return false;
                }

                // Missing-by-id is not resolved — and a stale id must NOT fall through to
                // path 2: its uniqueness guard presumes THIS ticket's escalation is among the
                // org's rows, and once the by-id read fails we cannot confirm which row that
                // is, so a lone match there may be a sibling and would close this ticket off
                // someone else's escalation. A 404 is NOT proof of deletion (see the class
                // docblock) — it is only the loss of the definitive read. Skip.
                //
                // Deliberately still warning, at the SAME level as any other fetch failure.
                // Downgrading it was tempting — a purged escalation is not a fault — but 404 is
                // exactly the status this code says it cannot attribute, and the causes the
                // docblock lists (narrowed key scope, changed base path, a gateway) return it
                // for EVERY ticket at once. That is the widest blast radius this service has,
                // and it would then be its quietest line. HuntressClient does log the failed
                // request at error level, but request-scoped and without a ticket id, so the
                // correlation below is the only per-ticket record that the skip happened.
                // No tombstone is persisted: the id is retried next run.
                Log::warning("[HuntressEscalationReconcile] getEscalation({$escalationId}) returned 404; skipping — a stale id cannot fall back to org+subject+window matching", [
                    'ticket_id' => $ticket->id,
                ]);

                return false;
            }

            // A successful fetch proves the escalation EXISTS. It does not prove it is THIS
            // ticket's, and that gap is the whole of #1390: a ticket carrying another tenant's
            // escalation URL would otherwise be auto-closed the moment that unrelated
            // escalation is resolved.
            //
            // The check applies to EVERY id, with NO provenance exemption. An earlier revision
            // of this guard exempted the ingest-captured metadata id on the reasoning that it
            // came from our own ingest of this alert's payload. That reasoning was false:
            // HuntressService captures it with `preg_match('#escalations/(\d+)#', $description)`
            // — the SAME unanchored pattern used here, over the same vendor-supplied body, nine
            // lines below a capture that IS host-anchored to huntress.io with a comment
            // explaining that this text can be attacker-influenced. Our ingest never validated
            // what it scraped, so "it is in metadata" is not a trust boundary.
            if (! $this->escalationBelongsToTicket($ticket, $escalation, $escalationId)) {
                return false;
            }

            return $this->isResolved($escalation);
        }

        // 2. Org + subject + window, uniquely — for tickets with NO stored id only (a stale id
        //    that 404s is skipped above: uniqueness cannot imply ownership once the by-id read
        //    for this ticket's own escalation has failed). SCOPE: requires the ticket's
        //    client to be org-mapped AND the escalation to carry that org — which excludes
        //    account-level escalations (e.g. "Failed to Deliver") entirely.
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
    /**
     * Ownership gate for the by-id path: does this fetched escalation correspond to the
     * ticket's client? Applied to every recovered id regardless of where it came from.
     *
     * FAILS CLOSED. Correspondence must be established positively; anything this method
     * cannot interpret is a refusal, never a pass. Three refusals, logged distinctly because
     * they need different operator responses:
     *   - org-associated but a DIFFERENT org — the #1390 cross-client vector; the loud one.
     *   - org-associated but the ticket's client is not org-mapped — a mapping gap here, not
     *     a vendor problem.
     *   - no usable organizations[] and not the documented account-level shape — an unreadable
     *     payload; we do not guess.
     *
     * ONE exception, deliberately narrow: an escalation with `organizations` PRESENT and
     * literally empty carries no organization association at all. That is the account-level
     * shape (integration health, e.g. "Failed to Deliver"), it is how HuntressReadOnlyToolset
     * ::escalationInScope already defines account-level, and an org comparison against it is
     * not merely unavailable but meaningless. Those escalations are exactly the ones path 2
     * structurally cannot reach. A payload that merely HAPPENS to yield no org ids — key
     * absent, not an array, or a non-empty list of malformed entries — is NOT that shape and
     * is refused.
     *
     * `type` was considered as the account-level discriminator and REJECTED: no production
     * code reads it, and this repo's own fixtures disagree about its values ('Escalation' in
     * this test file's escalationRow(), 'account'/'incident' in HuntressReadOnlyToolsetTest).
     * Resting a security gate on an unverified vendor contract is how the previous revision
     * of this guard went wrong; presence-and-emptiness is checkable here and now.
     *
     * A refusal TERMINATES the by-id path — the caller returns false rather than falling
     * through to path 2. The by-id read succeeded and told us this escalation is not this
     * ticket's; letting the weaker org+subject+window matcher then close the ticket anyway
     * would be a weaker matcher overriding a stronger negative. Same reasoning as the 404
     * branch above.
     *
     * @param  array<string, mixed>  $escalation
     */
    private function escalationBelongsToTicket(Ticket $ticket, array $escalation, int $escalationId): bool
    {
        $organizations = $escalation['organizations'] ?? null;
        $escalationOrgIds = $this->escalationOrgIds($escalation);

        if ($escalationOrgIds === []) {
            if (is_array($organizations) && $organizations === []) {
                return true;
            }

            Log::warning("[HuntressEscalationReconcile] getEscalation({$escalationId}) returned no usable organizations[] and is not the documented account-level shape; skipping — correspondence cannot be established, so this fails closed", [
                'ticket_id' => $ticket->id,
                'organizations_present' => is_array($organizations),
            ]);

            return false;
        }

        $ticketOrgId = $ticket->client?->huntress_organization_id;

        if ($ticketOrgId === null) {
            Log::warning("[HuntressEscalationReconcile] getEscalation({$escalationId}) is org-associated but the ticket's client has no huntress_organization_id; skipping — correspondence cannot be established, so this fails closed", [
                'ticket_id' => $ticket->id,
            ]);

            return false;
        }

        if (! in_array((int) $ticketOrgId, $escalationOrgIds, true)) {
            Log::warning("[HuntressEscalationReconcile] getEscalation({$escalationId}) belongs to a different organization than the ticket's client; skipping — resolving here would close one client's ticket off another client's escalation", [
                'ticket_id' => $ticket->id,
            ]);

            return false;
        }

        return true;
    }

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
     *
     * NO id this returns is self-certifying, and the caller treats them all alike. Every
     * source here traces back to text we did not author: `source_alert_id` and `description`
     * are free text that can carry any escalation URL, and the alert metadata id is itself
     * scraped by HuntressService out of the vendor payload body with an unanchored pattern.
     * An id is a lookup key, never evidence of ownership — see escalationBelongsToTicket().
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
