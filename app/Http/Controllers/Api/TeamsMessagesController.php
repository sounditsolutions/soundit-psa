<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Teams\TeamsIdentityResolver;
use App\Services\Teams\TeamsReplyService;
use App\Support\TeamsBotConfig;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
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
    ) {}

    public function handle(Request $request): JsonResponse
    {
        $activity = $request->json()->all();
        $activity = is_array($activity) ? $activity : [];

        // Per-person identity. Null (unknown / deactivated / cross-tenant) is already
        // audited inside the resolver; we never act on an unresolved sender.
        $sender = $this->resolver->resolve($activity);

        if (TeamsBotConfig::enabled()
            && $sender !== null
            && $this->botMentioned($activity)
            && $this->serviceUrlPinned($request, $activity)
        ) {
            $text = $this->stripMention((string) ($activity['text'] ?? ''));
            if ($text !== '') {
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
