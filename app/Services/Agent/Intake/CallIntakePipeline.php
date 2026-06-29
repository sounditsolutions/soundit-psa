<?php

namespace App\Services\Agent\Intake;

use App\Models\PhoneCall;
use App\Models\Ticket;
use App\Services\PhoneCallService;
use App\Support\AgentConfig;
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
            if (! $resolution->resolved && $call->client_id === null) {
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
     * UNRESOLVED → HOLD. For now this simply returns: the call stays in the existing
     * unknown-caller facet for a human to triage.
     *
     * Stage 6 (next task): spam assessment hooks in here; until then HOLD.
     * (Kept as a separate method so the next task has a clean extension point — do not
     * inline it.)
     */
    private function handleUnresolved(PhoneCall $call): void
    {
        // Stage 6 (next task): spam assessment hooks in here; until then HOLD.
    }
}
