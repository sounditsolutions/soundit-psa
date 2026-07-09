<?php

namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Controller;
use App\Models\PortalChatConversation;
use App\Services\Portal\PortalChatbotService;
use App\Support\PortalConfig;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Client-portal AI chatbot (psa-2ab).
 *
 * The feature is gated by `portal_chatbot_enabled` — when off the routes 404
 * and the nav link is hidden. When on but the AI provider is not configured,
 * the page renders in a disabled state and the send endpoint returns a 422.
 */
class PortalChatbotController extends Controller
{
    public function __construct(private readonly PortalChatbotService $chatbot) {}

    public function index(Request $request): View
    {
        abort_unless(PortalConfig::chatbotEnabled(), 404);

        $clientId = $request->attributes->get('portal_client_id');
        $person = $request->attributes->get('portal_person');

        // Resume this contact's most recent conversation, if any.
        $conversation = PortalChatConversation::where('client_id', $clientId)
            ->where('person_id', $person->id)
            ->latest('updated_at')
            ->first();

        $messages = $conversation
            ? $conversation->messages()->get(['role', 'content'])
            : collect();

        return view('portal.chatbot.index', [
            'available' => $this->chatbot->isAvailable(),
            'conversationId' => $conversation?->id,
            'messages' => $messages,
        ]);
    }

    public function send(Request $request): JsonResponse
    {
        abort_unless(PortalConfig::chatbotEnabled(), 404);

        $validated = $request->validate([
            'message' => 'required|string|max:2000',
            'conversation_id' => 'nullable|integer',
        ]);

        $clientId = $request->attributes->get('portal_client_id');
        $person = $request->attributes->get('portal_person');

        $conversation = $this->resolveConversation($validated['conversation_id'] ?? null, $clientId, $person->id);

        try {
            $message = $this->chatbot->sendMessage($conversation, $validated['message']);
        } catch (\RuntimeException $e) {
            // Expected guard failures (unavailable / over a limit) — safe to show.
            return response()->json(['error' => $e->getMessage()], 422);
        } catch (\Throwable $e) {
            report($e);

            return response()->json(['error' => 'Something went wrong. Please try again.'], 500);
        }

        return response()->json([
            'reply' => $message->content,
            'conversation_id' => $conversation->id,
        ]);
    }

    /**
     * Load and authorize an existing conversation, or start a new one bound to
     * this client + contact. A conversation belonging to another client or
     * contact is a 403 — never silently repurposed.
     */
    private function resolveConversation(?int $conversationId, int $clientId, int $personId): PortalChatConversation
    {
        if ($conversationId !== null) {
            $conversation = PortalChatConversation::find($conversationId);

            abort_if($conversation === null, 404);
            abort_unless(
                $conversation->client_id === $clientId && $conversation->person_id === $personId,
                403,
            );

            return $conversation;
        }

        return PortalChatConversation::create([
            'client_id' => $clientId,
            'person_id' => $personId,
        ]);
    }
}
