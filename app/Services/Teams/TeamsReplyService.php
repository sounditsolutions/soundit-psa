<?php

namespace App\Services\Teams;

use App\Models\AssistantConversation;
use App\Models\AssistantMessage;
use App\Models\TeamsPersona;
use App\Services\Ai\AiClient;
use App\Support\AgentConfig;
use App\Support\TeamsBotConfig;
use App\Support\TeamsPersonaConfig;
use App\Support\TechnicianConfig;
use Illuminate\Support\Facades\Log;

/**
 * Turns an inbound Teams staff message into a read-only, tool-using reply (E2a).
 *
 * Identity: the loop ALWAYS runs as the resolved PSA user (never a system user),
 * so every tool call is scoped to who actually spoke. The transcript is persisted
 * in the existing assistant_conversations / assistant_messages tables (no new
 * migration), one conversation per Teams conversation.
 *
 * Fail-soft: reply() never throws. A reply failure must not 500 the inbound
 * webhook, so the whole flow is wrapped in a \Throwable catch.
 */
class TeamsReplyService
{
    public function __construct(
        private AiClient $ai,
        private TeamsBotClient $client,
    ) {}

    /** Build an instance wired to the configured Opus model + container bot client. */
    public static function withConfiguredModel(): self
    {
        return new self(new AiClient(AgentConfig::agentModel()), app(TeamsBotClient::class));
    }

    public function reply(ResolvedSender $sender, string $text, string $mspName): void
    {
        try {
            // 0. Resolve the persona SERVER-SIDE from the signed-aud-derived personaKey
            // (Task 2) — never from anything caller-supplied. Null for the legacy
            // single-bot pilot, in which case every step below is byte-identical to
            // pre-P1 behavior.
            $persona = $sender->personaKey !== null ? TeamsPersonaConfig::byKey($sender->personaKey) : null;
            // Conditional (rather than an unconditional ->forPersona($persona) call) so
            // the null-persona path never even touches forPersona() — byte-identical
            // interaction with $this->client, not just byte-identical net behavior.
            $client = $persona !== null ? $this->client->forPersona($persona) : $this->client;

            // 1. Show we're working on it immediately.
            $this->typing($sender, $client);

            // 2. Persist the human turn so the model sees who spoke.
            $conversation = $this->conversation($sender, $persona);
            $conversation->messages()->create([
                'role' => 'user',
                'content' => $sender->user->name.': '.$text,
            ]);

            // 3-5. System prompt + recent transcript + read-only tools/executor.
            $system = $this->systemPrompt($mspName, $persona);
            $messages = $this->history($conversation);
            // The full read-only surface (general queue tools + PSA entity lookups +
            // integration reads), minus the two mutators (stripped + executor-refused).
            $tools = TeamsReadOnlyToolset::definitions();
            $executor = TeamsReadOnlyToolset::executor($sender->user->id);

            // 6. Run the tool loop (read-only), nudging typing on each tool call.
            $response = $this->ai->runChatWithTools(
                system: $system,
                messages: $messages,
                tools: $tools,
                executor: $executor,
                onToolCall: fn (string $tool) => $this->typing($sender, $client),
                enableCaching: true,
            );

            // 7. Send the reply (with a friendly fallback for an empty response).
            $replyText = trim($response->text);
            if ($replyText === '') {
                $replyText = "Sorry — I couldn't come up with anything useful there.";
            }
            $client->sendMessage($sender->serviceUrl, $sender->conversationId, $replyText);

            // 8. Persist the AI turn.
            $conversation->messages()->create([
                'role' => 'assistant',
                'content' => $replyText,
            ]);
        } catch (\Throwable $e) {
            Log::warning('[Teams Bot] reply failed', ['error' => $e->getMessage()]);

            return;
        }
    }

    /** Best-effort typing indicator; only when we have somewhere to send it. */
    private function typing(ResolvedSender $sender, TeamsBotClient $client): void
    {
        if ($sender->serviceUrl !== null && $sender->conversationId !== null) {
            $client->sendTyping($sender->serviceUrl, $sender->conversationId);
        }
    }

    /**
     * One conversation per Teams conversation, owned by the AI actor user — the
     * PERSONA's own actor_user_id when one is in play, else the legacy global AI
     * actor. Keyed by the unique external_key (not title), and created via
     * createOrFirst so a Teams retry / concurrent webhook can never split the
     * transcript into two rows — the unique index makes the racing insert fail
     * and re-select the winner.
     */
    private function conversation(ResolvedSender $sender, ?TeamsPersona $persona): AssistantConversation
    {
        return AssistantConversation::createOrFirst(
            ['external_key' => 'teams:'.$sender->conversationId],
            ['context_type' => 'teams_chat', 'user_id' => $persona?->actor_user_id ?? TechnicianConfig::aiActorUserId()],
        );
    }

    /**
     * The last ~20 turns, oldest → newest, mapped to Anthropic message shape.
     * Queried off the model directly: the messages() relation is pre-ordered by id
     * ascending, so an orderByDesc on it would be a no-op for the newest-N slice.
     *
     * @return array<int, array{role: string, content: string}>
     */
    private function history(AssistantConversation $conversation): array
    {
        return AssistantMessage::where('conversation_id', $conversation->id)
            ->orderByDesc('id')
            ->limit(20)
            ->get()
            ->reverse()
            ->map(fn (AssistantMessage $m): array => ['role' => $m->role, 'content' => $m->content])
            ->values()
            ->all();
    }

    private function systemPrompt(string $mspName, ?TeamsPersona $persona): string
    {
        $personaName = $persona?->display_name ?? TechnicianConfig::aiActorName();

        // Banter dial (E2b): a bit of warmth/personality when enabled — Charlie wants a
        // teammate, not a form. Tuned live via teams_ambient_banter.
        $banter = TeamsBotConfig::ambientBanter()
            ? ' A little warmth and friendly personality is welcome — you are a teammate, not a form.'
            : '';

        return "You are {$personaName}, a helpful teammate in {$mspName}'s internal staff Teams chat. "
            .'Be concise, friendly, and genuinely useful. You can look things up with your tools '
            ."but you cannot change anything (read-only). If you are unsure, say so.{$banter}";
    }
}
