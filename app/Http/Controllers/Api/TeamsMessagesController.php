<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Teams\TeamsIdentityResolver;
use App\Support\TeamsBotConfig;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Inbound Bot Framework (Teams) receiver — Increment E1: the secure pipe only.
 *
 * The JWT has already been verified FAIL-CLOSED by VerifyBotFrameworkJwt before
 * this runs. Here we resolve WHO sent the turn (the real PSA user, never a shared
 * account) and return a benign 200 ack. E1 has NO conversation logic — it never
 * replies (that is E2). The dormancy flag is the seam E2 branches on; in E1 the
 * response is always a 200 ack so the channel does not retry, and an unresolved
 * sender is audited by the resolver and simply not acted upon.
 */
class TeamsMessagesController extends Controller
{
    public function __construct(
        private readonly TeamsIdentityResolver $resolver,
    ) {}

    public function handle(Request $request): JsonResponse
    {
        $activity = $request->json()->all();
        $activity = is_array($activity) ? $activity : [];

        // Per-person identity. Null (unknown / deactivated / cross-tenant) is already
        // audited inside the resolver; we never act on an unresolved sender.
        $sender = $this->resolver->resolve($activity);

        if ($sender !== null) {
            Log::info('[Teams Bot] Authenticated turn received', [
                'user_id' => $sender->user->id,
                'conversation_id' => $sender->conversationId,
                // E1 is dormant: no reply, no action — E2 adds the conversational loop.
                'enabled' => TeamsBotConfig::enabled(),
            ]);
        }

        return response()->json(['status' => 'ok']);
    }
}
