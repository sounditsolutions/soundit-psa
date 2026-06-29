<?php

namespace App\Services\Agent\Intake;

use App\Enums\PhoneDirectoryListType;
use App\Models\PhoneCall;
use App\Models\PhoneDirectoryEntry;
use App\Models\Ticket;
use App\Services\PhoneCallService;
use App\Support\AgentConfig;
use App\Support\PhoneNumber;
use App\Support\TriageConfig;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * The keystone of the AI call-intake leg: a channel-neutral, DORMANT, HELD-FIRST,
 * FAIL-SOFT orchestrator that routes a transcribed call to an existing ticket or a
 * new one, recording an observational cockpit run. It is the call-channel mirror of
 * EmailService::routeInboundEmail.
 *
 * REUSABLE ENTRY (Charlie's roadmap): handle(PhoneCall) is the reusable seam a future
 * ElevenLabs voice agent will call with a synthesized call record. It therefore reads
 * only NEUTRAL PhoneCall fields (call_summary / cleaned_transcript / client_id /
 * from_number) and keeps Plivo / transcription specifics OUT — those belong to the
 * dispatch site (TranscriptionService).
 *
 * It composes already-built pieces and decides NOTHING those pieces already decide:
 *   - CallerResolver::resolve     (T3) — who is calling
 *   - IntakeRouter::routeContent  (T1) — attach vs create (with the injection floor)
 *   - PhoneCallService link/create (T4) — apply the chosen action
 *   - IntakeRecorder::record      (T4) — write the observational intake_route run
 *
 * Safety posture:
 *   - DORMANT: when intake is disabled the pipeline returns immediately (defence in
 *     depth — the dispatch site is also gated, so no job is even queued).
 *   - HELD-FIRST: an auto-attach happens ONLY when the operator set a threshold AND the
 *     router's confidence meets it AND the candidate ticket re-validates server-side
 *     (same client, still open). The safe default is always create-new.
 *   - FAIL-SOFT: the whole body is wrapped — any error logs and returns. The call record
 *     is already saved and surfaced in the calls list, so intake never loses it.
 *   - UNRESOLVED = HOLD: handled by a dedicated method (the next task extends it).
 */
class CallIntakePipeline
{
    public function handle(PhoneCall $call): void
    {
        try {
            // ── Stage 1: dormant gate (defence in depth) ─────────────────────
            if (! AgentConfig::intakeEnabled()) {
                return;
            }

            // ── Stage 2: skip an already-ticketed call ───────────────────────
            // An answered / outbound call a tech linked live is left alone.
            // Voicemails are never live-linked, so this only short-circuits genuine
            // human (or prior-intake) attachments.
            if ($call->isLinkedToTicket()) {
                return;
            }

            // ── Stage 3: resolve the caller (pure read, no side effects) ─────
            $resolution = app(CallerResolver::class)->resolve($call);

            // ── Stage 4: apply a NEW resolution onto the call ────────────────
            // So the created ticket and any future lookup carry the client/person.
            if ($call->client_id === null && $resolution->resolved && $resolution->clientId !== null) {
                $call->client_id = $resolution->clientId;
                $call->person_id = $resolution->personId; // may be null (company-only match)
                $call->save();
            }

            // ── Stage 5: route (held-first, mirroring routeInboundEmail) ──────
            // M1 hardening (T5 review): a resolved-but-null-client result (resolved=true
            // yet clientId=null) is NOT applied by stage 4, so it must ALSO route to HOLD
            // — otherwise it falls through to routeContent($clientId=null,…) → TypeError.
            if (($resolution->resolved === false || $resolution->clientId === null) && $call->client_id === null) {
                $this->handleUnresolved($call); // HOLD (stage 6)

                return;
            }

            $clientId = $call->client_id; // set by stage 4 or pre-existing
            $subject = Str::limit((string) $call->call_summary, 200);
            $body = (string) ($call->call_summary ?: $call->cleaned_transcript ?: $call->transcription ?: '');

            $decision = app(IntakeRouter::class)->routeContent($clientId, $subject, $body, 'call:'.$call->id);
            $threshold = AgentConfig::intakeAttachAutoThreshold();

            // GRADUATED auto-attach — only when confident AND the threshold is set.
            if ($decision->isAttach() && $threshold !== null && $decision->confidence >= $threshold) {
                $ticket = Ticket::find($decision->ticketId);

                // Re-validate server-side: same client + still open (may have changed since route).
                if ($ticket && $ticket->client_id === $clientId && $ticket->status->isOpen()) {
                    app(PhoneCallService::class)->linkCallToTicketWithNote(
                        $call,
                        $ticket->id,
                        "Linked to this ticket by AI intake (call #{$call->id}).\n\n".(string) $call->call_summary,
                    );
                    app(IntakeRecorder::class)->record(
                        $clientId,
                        'call:'.$call->id,
                        $decision,
                        attachedTicketId: $ticket->id,
                        createdTicketId: null,
                        meta: ['call_id' => $call->id, 'source' => 'call'],
                    );

                    return;
                }
                // ticket vanished / closed / cross-client → fall through to the safe create.
            }

            // Safe default: create a new ticket (at most once), plus an observational
            // record when the router suggested an attach we chose to hold.
            if ($call->fresh()->ticket_id === null) {
                app(PhoneCallService::class)->createTicketFromCall($call);
            }

            if ($decision->isAttach()) {
                app(IntakeRecorder::class)->record(
                    $clientId,
                    'call:'.$call->id,
                    $decision,
                    attachedTicketId: null,
                    createdTicketId: $call->fresh()->ticket_id,
                    meta: ['call_id' => $call->id, 'source' => 'call'],
                );
            }
        } catch (\Throwable $e) {
            // FAIL-SOFT: the call is already saved and surfaced — never lose it to an
            // intake error. Log and return.
            Log::warning('[CallIntake] pipeline failed — call left for a human', [
                'call_id' => $call->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * UNRESOLVED → assess for spam, then HOLD or (rarely) AUTO-block.
     *
     * A genuinely-new unknown call still HOLDs (returns) for a human to triage. A
     * suspected-spam call persists its confidence as intake_spam_score so the cockpit can
     * surface a one-tap "block & dismiss" suggestion (next task). Only ABOVE an operator-set
     * threshold (null by default = NEVER) does it graduate to an AUTO mark+block.
     *
     * Runs inside handle()'s fail-soft try/catch (the spam assessment is itself fail-soft to
     * notSpam, so an AI failure simply HOLDs).
     */
    private function handleUnresolved(PhoneCall $call): void
    {
        $verdict = app(SpamAssessor::class)->assess($call);
        if (! $verdict->isSpam) {
            return; // real-looking unknown → HOLD (unknown-caller facet), unchanged
        }

        // Suspected spam: graduate to AUTO mark+block ONLY above an operator-set threshold.
        // Requires ALL of: a non-null threshold, confidence ≥ it, AND a system user for
        // attribution. Any missing → fall through to the safe suggest (persist) path.
        $threshold = AgentConfig::intakeSpamBlockAutoThreshold();
        $systemUserId = TriageConfig::systemUserId();
        if ($threshold !== null && $verdict->confidence >= $threshold && $systemUserId !== null) {
            $this->autoBlockSpam($call, $verdict, $systemUserId);

            return;
        }

        // Default (day-1): persist the suspicion for the cockpit one-tap suggestion.
        $call->intake_spam_score = $verdict->confidence;
        $call->save();
    }

    /**
     * AUTO mark-followed-up + block the caller's number. The one semi-destructive automated
     * path in the intake leg — reached ONLY when an operator set a non-null block threshold
     * the confidence cleared, with a system user to attribute the action to.
     *
     * Idempotent: updateOrCreate keys on the normalized number, so a repeat spam call from
     * the same number re-asserts the block rather than erroring on the unique index.
     */
    private function autoBlockSpam(PhoneCall $call, SpamVerdict $verdict, int $systemUserId): void
    {
        app(PhoneCallService::class)->markFollowedUp($call, $systemUserId);

        $normalized = PhoneNumber::normalize($call->from_number);
        if ($normalized !== null) {
            PhoneDirectoryEntry::updateOrCreate(
                ['phone_number' => $normalized],
                [
                    'list_type' => PhoneDirectoryListType::Blocked,
                    'reason' => 'AI intake: auto-blocked suspected spam',
                    'added_by_user_id' => $systemUserId,
                ],
            );
        }

        Log::warning('[CallIntake] auto-blocked suspected spam', [
            'call_id' => $call->id,
            'confidence' => $verdict->confidence,
        ]);
    }
}
