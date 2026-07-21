<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\AssistantConversation;
use App\Models\AssistantMessage;
use App\Models\Client;
use App\Models\Ticket;
use App\Services\Assistant\AssistantService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;

class AssistantController extends Controller implements HasMiddleware
{
    /**
     * psa-uw2o.2: the gate binds to the CONTROLLER, not to a location in
     * routes/web.php. The route group alone only covered routes written inside
     * it — a new route added elsewhere would have missed it, which is weaker
     * than the invariant that was claimed for it. Mirrors WikiController, which
     * gates its module the same way ("wiki_enabled is the master switch").
     *
     * The group in routes/web.php is kept so the gate is visible where the
     * routes are read; this is what actually enforces it.
     */
    public static function middleware(): array
    {
        return ['assistant.enabled'];
    }

    public function getMessages(AssistantConversation $conversation): JsonResponse
    {
        if ($conversation->user_id !== auth()->id()) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        $messages = $conversation->messages()->orderBy('id')->get()
            ->map(fn (AssistantMessage $m) => [
                'id' => $m->id,
                'role' => $m->role,
                'content' => $m->content,
            ]);

        return response()->json([
            'id' => $conversation->id,
            'context_type' => $conversation->context_type,
            'context_id' => $conversation->context_id,
            'title' => $conversation->title,
            'messages' => $messages,
        ]);
    }

    public function createConversation(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'context_type' => 'nullable|in:ticket,client|required_with:context_id',
            'context_id' => 'nullable|integer|required_with:context_type',
        ]);

        // Validate context_id exists if context_type is set
        if (! empty($validated['context_type']) && ! empty($validated['context_id'])) {
            if ($validated['context_type'] === 'ticket') {
                $ticket = Ticket::find($validated['context_id']);
                if (! $ticket) {
                    return response()->json(['error' => 'Ticket not found'], 404);
                }
            } elseif ($validated['context_type'] === 'client') {
                $client = Client::find($validated['context_id']);
                if (! $client) {
                    return response()->json(['error' => 'Client not found'], 404);
                }
            }
        }

        $conversation = AssistantConversation::create([
            'user_id' => auth()->id(),
            'context_type' => $validated['context_type'] ?? null,
            'context_id' => $validated['context_id'] ?? null,
        ]);

        return response()->json([
            'id' => $conversation->id,
            'context_type' => $conversation->context_type,
            'context_id' => $conversation->context_id,
        ]);
    }

    public function sendMessage(Request $request, AssistantConversation $conversation): JsonResponse
    {
        // Ownership check
        if ($conversation->user_id !== auth()->id()) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        $validated = $request->validate([
            'message' => 'required|string|max:10000',
        ]);

        $service = new AssistantService;

        try {
            $result = $service->sendMessage($conversation, $validated['message']);
        } catch (\RuntimeException $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('[Assistant] Unexpected error', [
                'conversation_id' => $conversation->id,
                'error' => $e->getMessage(),
            ]);

            return response()->json(['error' => 'An error occurred while processing your request. Please try again.'], 500);
        }

        $message = $result['message'];

        return response()->json([
            'id' => $message->id,
            'role' => $message->role,
            'content' => $message->content,
            'tools_used' => $result['tools_used'],
            'input_tokens' => $message->input_tokens,
            'output_tokens' => $message->output_tokens,
        ]);
    }

    public function saveNote(Request $request, AssistantConversation $conversation): JsonResponse
    {
        // Ownership check
        if ($conversation->user_id !== auth()->id()) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        $validated = $request->validate([
            'message_id' => 'required|integer',
        ]);

        $message = AssistantMessage::where('id', $validated['message_id'])
            ->where('conversation_id', $conversation->id)
            ->where('role', 'assistant')
            ->first();

        if (! $message) {
            return response()->json(['error' => 'Message not found'], 404);
        }

        // Resolve ticket — save as note only works in ticket context
        $ticket = $conversation->resolveTicket();
        if (! $ticket) {
            return response()->json(['error' => 'Save as note is only available in ticket context'], 422);
        }

        $service = new AssistantService;
        $note = $service->saveAsNote($message, $ticket, auth()->id());

        return response()->json([
            'success' => true,
            'note_id' => $note->id,
        ]);
    }

    /**
     * Get all AI conversations for a ticket, newest first.
     * Used by the timeline to render inline chat blocks.
     */
    public function forTicket(Ticket $ticket)
    {
        $conversations = AssistantConversation::where('context_type', 'ticket')
            ->where('context_id', $ticket->id)
            ->with(['user:id,name', 'messages'])
            ->orderByDesc('created_at')
            ->get()
            ->map(fn ($conv) => [
                'id' => $conv->id,
                'user_id' => $conv->user_id,
                'user_name' => $conv->user?->name ?? 'Unknown',
                'message_count' => $conv->messages->count(),
                'created_at' => $conv->created_at->toIso8601String(),
                'updated_at' => $conv->updated_at->toIso8601String(),
                'is_active' => $conv->user_id === auth()->id()
                    && $conv->messages->last()?->created_at?->gt(now()->subMinutes(30)),
                'messages' => $conv->messages->map(fn ($m) => [
                    'id' => $m->id,
                    'role' => $m->role,
                    'content' => $m->content,
                    'created_at' => $m->created_at->toIso8601String(),
                ]),
            ]);

        return response()->json($conversations);
    }

    /**
     * Get or create the current user's general assistant conversation.
     */
    public function general(): JsonResponse
    {
        $conversation = AssistantConversation::firstOrCreate(
            [
                'user_id' => auth()->id(),
                'context_type' => null,
                'context_id' => null,
            ],
            ['title' => 'General Assistant']
        );

        $messages = $conversation->messages()
            ->orderBy('id')
            ->get(['id', 'role', 'content', 'created_at']);

        return response()->json([
            'id' => $conversation->id,
            'messages' => $messages,
        ]);
    }
}
