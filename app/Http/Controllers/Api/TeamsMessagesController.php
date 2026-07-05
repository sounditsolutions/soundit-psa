<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\OperatorInbox;
use App\Services\Chet\OperatorBridgeTextSanitizer;
use App\Services\Teams\ResolvedSender;
use App\Services\Teams\TeamsAmbientService;
use App\Services\Teams\TeamsIdentityResolver;
use App\Services\Teams\TeamsReplyService;
use App\Support\TeamsBotConfig;
use App\Support\TeamsPersonaConfig;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * Inbound Bot Framework (Teams) receiver.
 *
 * E1 (the secure pipe): the JWT was already verified FAIL-CLOSED by
 * VerifyBotFrameworkJwt; here we resolve WHO sent the turn (the real PSA user,
 * never a shared account).
 *
 * E2a (the conversational REPLY loop): when the bridge is enabled AND the bot is
 * mentioned by a RESOLVED user AND the reply destination is pinned to the signed
 * serviceUrl claim, run the read-only conversational loop and reply. Everything
 * else just acks 200 (so the channel never retries) with no reply. Mention-only —
 * E2b adds the ambient Haiku-gated chiming-in. Read-only — E3 adds gated writes.
 */
class TeamsMessagesController extends Controller
{
    public function __construct(
        private readonly TeamsIdentityResolver $resolver,
        private readonly TeamsReplyService $replyService,
        private readonly TeamsAmbientService $ambient,
        private readonly OperatorBridgeTextSanitizer $textSanitizer,
    ) {}

    public function handle(Request $request): JsonResponse
    {
        $activity = $request->json()->all();
        $activity = is_array($activity) ? $activity : [];

        // Per-person identity. Null (unknown / deactivated / cross-tenant / a
        // signed-aud-vs-recipient mismatch) is already audited inside the resolver; we
        // never act on an unresolved sender. The validated aud (P1 — Teams AI-Staff
        // Personas) is threaded through so the resolver can bind persona/routing
        // resolution to the SIGNED claim rather than the activity body.
        $sender = $this->resolver->resolve($activity, $request->attributes->get('teams_bot_app_id'));

        // Auto-capture (P2 hardening item 8): on a resolved persona's FIRST inbound
        // turn, self-bind its operator-lane conversation from this already aud-
        // verified + serviceUrl-pinned activity. Must run BEFORE routedToPersona()
        // below so that very first turn is also correctly recognised as its own —
        // see captureConversationRefs()'s docblock for the full interaction note.
        if ($sender !== null) {
            $this->captureConversationRefs($sender, $activity, $request);
        }

        // A resolved, ENABLED persona is its own gate everywhere below — the shared
        // teams_bot_enabled toggle only governs the legacy single-bot path (mirrors
        // OperatorDelivery::send()'s persona-is-its-own-gate rule, Task 3 Cluster B).
        $personaActive = $sender?->personaKey !== null;

        if ($this->routedToPersona($sender, $activity)) {
            if (! ($personaActive || TeamsBotConfig::enabled())) {
                Log::info('[Teams Bot] Chet-routed turn ignored because Teams bot is disabled', [
                    'conversation_id' => $activity['conversation']['id'] ?? null,
                ]);
            } elseif ($sender === null) {
                Log::warning('[Teams Bot] Chet-routed turn from unresolved sender dropped', [
                    'conversation_id' => $activity['conversation']['id'] ?? null,
                ]);
            } elseif ($this->serviceUrlPinned($request, $activity)) {
                $this->enqueueOperatorMessage($sender, $activity);
            }

            return response()->json(['status' => 'ok']);
        }

        if (($personaActive || TeamsBotConfig::enabled())
            && $sender !== null
            && $this->serviceUrlPinned($request, $activity)
        ) {
            $text = $this->stripMention((string) ($activity['text'] ?? ''));
            if ($text !== '' && $this->shouldReply($sender, $text, $activity)) {
                // Run as the resolved user; the reply service is fail-soft (never throws).
                $this->replyService->reply($sender, $text, (string) config('app.name', 'our team'));
            }
        } elseif ($sender !== null) {
            Log::info('[Teams Bot] Authenticated turn received (no reply)', [
                'user_id' => $sender->user->id,
                'conversation_id' => $sender->conversationId,
                'enabled' => TeamsBotConfig::enabled(),
                'persona_active' => $personaActive,
            ]);
        }

        return response()->json(['status' => 'ok']);
    }

    /**
     * Auto-bind a resolved persona's operator-lane conversation on first contact
     * (P2 hardening item 8). Guarded so it only ever WRITES once per persona:
     * $sender must carry a personaKey (never fires for the legacy single-bot
     * path), the serviceUrl must be pinned to the signed JWT claim (so the value
     * captured is never attacker-influenceable), the activity must carry a
     * non-empty conversation id, and the persona must not already have one bound
     * — an existing binding is NEVER overwritten by a later turn. Fail-soft: a
     * save failure is logged and swallowed, never turning an inbound ack into a
     * 500. Decision (documented for the PR): this binds the persona to its
     * (already aud-verified, hence safe) bot conversation on first contact so its
     * subsequent DMs route consistently to its own operator lane, without a
     * per-persona provisioning wizard. Interaction with item 3: because this runs
     * BEFORE routedToPersona() below, the very first turn both captures AND is
     * itself routed to the operator lane; the persona-is-its-own-gate reply path
     * is reached only once a persona is already bound elsewhere and is mentioned
     * from a different conversation — see PersonaReplyGateTest.
     */
    private function captureConversationRefs(ResolvedSender $sender, array $activity, Request $request): void
    {
        if ($sender->personaKey === null || ! $this->serviceUrlPinned($request, $activity)) {
            return;
        }

        $conversationId = $activity['conversation']['id'] ?? null;
        if (! is_string($conversationId) || $conversationId === '') {
            return;
        }

        $persona = TeamsPersonaConfig::byKey($sender->personaKey);
        if ($persona === null || (($persona->conversation_refs ?? [])['conversation_id'] ?? null) !== null) {
            return;
        }

        try {
            $persona->forceFill([
                'conversation_refs' => [
                    'conversation_id' => $conversationId,
                    'service_url' => $request->attributes->get('teams_bot_service_url'),
                ],
            ])->save();
        } catch (\Throwable $e) {
            Log::info('[Teams Bot] Persona conversation auto-capture failed (fail-soft)', [
                'persona_key' => $sender->personaKey,
                'conversation_id' => $conversationId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * True iff this activity belongs in an OPERATOR lane — either a resolved
     * persona's OWN operator conversation, or (no persona) the shared legacy
     * Chet conversation. Symmetric with Task 3's outbound fail-closed rule
     * (postToOperator(): an ENABLED persona with incomplete/mismatched targets
     * DROPS rather than falling back to the shared legacy lane) — a resolved
     * persona whose conversation doesn't match returns false outright here
     * too, it never falls through to the legacy check below.
     */
    private function routedToPersona(?ResolvedSender $sender, array $activity): bool
    {
        $conversationId = $activity['conversation']['id'] ?? null;
        if (! is_string($conversationId) || $conversationId === '') {
            return false;
        }

        if ($sender?->personaKey !== null) {
            $persona = TeamsPersonaConfig::byKey($sender->personaKey);

            return $persona !== null
                && (($persona->conversation_refs ?? [])['conversation_id'] ?? null) === $conversationId;
        }

        return TeamsBotConfig::chetRoutingEnabled()
            && TeamsBotConfig::chetConversationId() !== null
            && $conversationId === TeamsBotConfig::chetConversationId();
    }

    private function enqueueOperatorMessage(ResolvedSender $sender, array $activity): void
    {
        $senderUserId = $sender->user->id;

        OperatorInbox::create([
            'conversation_id' => (string) ($activity['conversation']['id'] ?? ''),
            'persona' => $sender->personaKey,
            'kind' => 'human',
            'sender_user_id' => $senderUserId,
            'sender_persona' => null,
            'text' => $this->textSanitizer->sanitizeForPrompt($this->stripMention((string) ($activity['text'] ?? ''))),
            'ts' => $this->activityTimestamp($activity),
            'direct_mention' => $this->botMentioned($activity),
            'authorized_steer' => $senderUserId !== null
                && in_array($senderUserId, TeamsBotConfig::operatorAllowlistUserIds(), true),
            'delivered_at' => null,
        ]);
    }

    private function activityTimestamp(array $activity): Carbon
    {
        $ts = $activity['timestamp'] ?? null;
        if (is_string($ts) && $ts !== '') {
            try {
                return Carbon::parse($ts);
            } catch (\Throwable) {
                // Use the receive time when Teams sends a malformed timestamp.
            }
        }

        return now();
    }

    /**
     * An @mention always replies (E2a) — no gate. A non-mention replies only when the
     * E2b ambient service decides to chime in (its own double-dormancy flag + cooldown
     * + the conservative Haiku gate). Short-circuit: an @mention never runs the gate.
     */
    private function shouldReply(ResolvedSender $sender, string $text, array $activity): bool
    {
        return $this->botMentioned($activity) || $this->ambient->shouldChimeIn($sender, $text);
    }

    /** True iff the activity @mentions THIS bot (its recipient.id). */
    private function botMentioned(array $activity): bool
    {
        $botId = $activity['recipient']['id'] ?? null;
        if ($botId === null) {
            return false;
        }

        foreach ($activity['entities'] ?? [] as $entity) {
            if (is_array($entity)
                && ($entity['type'] ?? '') === 'mention'
                && ($entity['mentioned']['id'] ?? null) === $botId) {
                return true;
            }
        }

        return false;
    }

    /** Strip the <at>…</at> mention markup so the model sees just the user's words. */
    private function stripMention(string $text): string
    {
        return trim((string) preg_replace('/<at\b[^>]*>.*?<\/at>/is', '', $text));
    }

    /**
     * Pin the reply destination to the VALIDATED serviceUrl claim (surfaced by the JWT
     * middleware) === the activity's serviceUrl. Fail-closed: a missing or mismatched
     * claim means no reply (defence in depth with TeamsBotClient's host allowlist).
     */
    private function serviceUrlPinned(Request $request, array $activity): bool
    {
        $claim = $request->attributes->get('teams_bot_service_url');
        $activityUrl = $activity['serviceUrl'] ?? null;

        // A trailing slash on the serviceUrl is not security-relevant (same host +
        // path); normalise it away so an inconsequential "/" difference between the
        // signed claim and the activity body can't fail the pin closed.
        $matches = is_string($claim) && $claim !== '' && is_string($activityUrl)
            && rtrim($claim, '/') === rtrim($activityUrl, '/');

        if (! $matches) {
            // serviceUrls are not secrets — log both so a residual mismatch is
            // self-diagnosing instead of needing another live round-trip.
            Log::warning('[Teams Bot] serviceUrl not pinned to the signed claim — not replying', [
                'conversation_id' => $activity['conversation']['id'] ?? null,
                'claim' => is_string($claim) ? $claim : '(missing)',
                'activity_service_url' => is_string($activityUrl) ? $activityUrl : '(missing)',
            ]);

            return false;
        }

        return true;
    }
}
