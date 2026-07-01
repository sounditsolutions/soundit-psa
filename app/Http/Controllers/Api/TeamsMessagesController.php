<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\OperatorInbox;
use App\Services\Teams\ResolvedSender;
use App\Services\Teams\TeamsAmbientService;
use App\Services\Teams\TeamsIdentityResolver;
use App\Services\Teams\TeamsReplyService;
use App\Support\TeamsBotConfig;
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
    ) {}

    public function handle(Request $request): JsonResponse
    {
        $activity = $request->json()->all();
        $activity = is_array($activity) ? $activity : [];

        // Per-person identity. Null (unknown / deactivated / cross-tenant) is already
        // audited inside the resolver; we never act on an unresolved sender.
        $sender = $this->resolver->resolve($activity);

        if ($this->routedToChet($activity)) {
            $this->enqueueOperatorMessage($sender, $activity);

            return response()->json(['status' => 'ok']);
        }

        if (TeamsBotConfig::enabled()
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
            ]);
        }

        return response()->json(['status' => 'ok']);
    }

    private function routedToChet(array $activity): bool
    {
        if (! TeamsBotConfig::chetRoutingEnabled()) {
            return false;
        }

        $chetConversationId = TeamsBotConfig::chetConversationId();
        $conversationId = $activity['conversation']['id'] ?? null;

        return $chetConversationId !== null
            && is_string($conversationId)
            && $conversationId === $chetConversationId;
    }

    private function enqueueOperatorMessage(?ResolvedSender $sender, array $activity): void
    {
        $senderUserId = $sender?->user->id;

        OperatorInbox::create([
            'conversation_id' => (string) ($activity['conversation']['id'] ?? ''),
            'sender_user_id' => $senderUserId,
            'text' => $this->stripMention((string) ($activity['text'] ?? '')),
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
