<?php

namespace App\Services\Assistant;

use App\Enums\NoteType;
use App\Helpers\MarkdownRenderer;
use App\Models\AssistantConversation;
use App\Models\AssistantMessage;
use App\Models\Ticket;
use App\Models\TicketNote;
use App\Services\Ai\AiClient;
use App\Services\AttachmentService;
use App\Services\Triage\ContextBuilder;
use App\Support\AiConfig;
use App\Support\AssistantConfig;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class AssistantService
{
    private const MAX_HISTORY_CHARS = 80_000;

    private const MAX_ROUNDS = 10;

    private const WALL_CLOCK_SECONDS = 120;

    private const TOKEN_BUDGET_PER_TURN = 200_000;

    /**
     * Send a message and get an AI response.
     *
     * @return array{message: AssistantMessage, tools_used: string[]}
     *
     * @throws \RuntimeException
     */
    public function sendMessage(AssistantConversation $conversation, string $userMessage): array
    {
        // Validate AI is configured with Anthropic
        if (! AiConfig::isConfigured() || AiConfig::provider() !== 'anthropic') {
            throw new \RuntimeException('AI assistant requires Anthropic provider to be configured.');
        }

        // Check conversation length
        $messageCount = $conversation->messages()->count();
        $maxMessages = AssistantConfig::maxMessagesPerConversation();
        if ($messageCount >= $maxMessages) {
            throw new \RuntimeException("Conversation has reached the {$maxMessages} message limit. Please start a new conversation.");
        }

        // Check daily token limit
        $dailyLimit = AssistantConfig::dailyTokenLimit();
        $todayTokens = AssistantMessage::whereHas('conversation', fn ($q) => $q->where('user_id', $conversation->user_id))
            ->whereDate('created_at', today())
            ->sum(DB::raw('input_tokens + output_tokens'));

        if ($todayTokens >= $dailyLimit) {
            throw new \RuntimeException('Daily AI assistant token limit reached. Try again tomorrow.');
        }

        // Store user message
        $userMsg = $conversation->messages()->create([
            'role' => 'user',
            'content' => $userMessage,
        ]);

        // Auto-set title from first user message
        if (! $conversation->title) {
            $conversation->update([
                'title' => mb_substr($userMessage, 0, 100),
            ]);
        }

        // Build system prompt
        $system = $this->buildSystemPrompt($conversation);

        // Build message history for API
        $apiMessages = $this->buildMessageHistory($conversation);

        // Resolve tools and executor
        $clientId = $conversation->resolveClientId();
        $ticket = $conversation->resolveTicket();
        $hasClient = $clientId !== null;

        // If this is a ticket conversation and the ticket has image attachments
        // (screenshots from email, manually attached, etc.), augment the FIRST
        // user message with image blocks so Claude can actually see them. The
        // text in the system prompt describes the ticket; this gives the model
        // the pixels. Images are rebuilt each turn so newly-attached images
        // become visible without restarting the conversation.
        if ($ticket && ! empty($apiMessages) && AiConfig::provider() === 'anthropic') {
            $imageBlocks = $this->buildTicketImageBlocks($ticket);
            if (! empty($imageBlocks)) {
                $first = $apiMessages[0];
                $originalContent = $first['content'];
                $apiMessages[0]['content'] = array_merge(
                    $imageBlocks,
                    [['type' => 'text', 'text' => is_string($originalContent) ? $originalContent : '']],
                );
            }
        }

        $tools = AssistantToolDefinitions::getTools($hasClient);
        $executor = new AssistantToolExecutor($ticket, $clientId, $conversation->user_id);

        // Track tool usage
        $toolsUsed = [];
        $onToolCall = function (string $toolName) use (&$toolsUsed) {
            $toolsUsed[] = $toolName;
        };

        // Run AI with tools
        $ai = new AiClient;
        $response = $ai->runChatWithTools(
            system: $system,
            messages: $apiMessages,
            tools: $tools,
            executor: [$executor, 'execute'],
            maxRounds: self::MAX_ROUNDS,
            maxTokenBudget: self::TOKEN_BUDGET_PER_TURN,
            wallClockSeconds: self::WALL_CLOCK_SECONDS,
            onToolCall: $onToolCall,
            enableCaching: true,
        );

        // Store assistant response
        $inputTokens = $ai->cumulativeInputTokens();
        $outputTokens = $ai->cumulativeOutputTokens();

        $assistantMsg = $conversation->messages()->create([
            'role' => 'assistant',
            'content' => $response->text,
            'input_tokens' => $inputTokens,
            'output_tokens' => $outputTokens,
        ]);

        // Update conversation token totals
        $conversation->increment('total_input_tokens', $inputTokens);
        $conversation->increment('total_output_tokens', $outputTokens);

        Log::info('[Assistant] Message processed', [
            'conversation_id' => $conversation->id,
            'input_tokens' => $inputTokens,
            'output_tokens' => $outputTokens,
            'tools_used' => $toolsUsed,
            'rounds' => count($toolsUsed) > 0 ? count($toolsUsed) : 1,
        ]);

        return [
            'message' => $assistantMsg,
            'tools_used' => array_unique($toolsUsed),
        ];
    }

    /**
     * Save an assistant message as a private note on a ticket.
     */
    public function saveAsNote(AssistantMessage $message, Ticket $ticket, int $userId): TicketNote
    {
        $body = "**AI Assistant:**\n\n".$message->content;

        return TicketNote::create([
            'ticket_id' => $ticket->id,
            'author_id' => $userId,
            'body' => $body,
            'body_html' => MarkdownRenderer::render($body),
            'note_type' => NoteType::Note,
            'is_private' => true,
            'noted_at' => now(),
        ]);
    }

    private function buildSystemPrompt(AssistantConversation $conversation): string
    {
        $prompt = <<<'PROMPT'
You are an AI assistant for MSP technicians. You help investigate issues, answer questions about clients and their infrastructure, and provide technical guidance.

You have access to read-only tools for querying ticket history, device health, email security, and Microsoft 365 data. Use tools proactively when they would help answer the question — do not guess when you can look it up.

Be concise and actionable. Reference ticket IDs and device hostnames specifically. Use markdown formatting. When you use a tool, briefly note what you found so the technician can verify your sources.
PROMPT;

        // Add context based on conversation type
        if ($conversation->context_type === 'ticket') {
            $ticket = $conversation->resolveTicket();
            if ($ticket) {
                // Any Tactical telemetry block included via buildForTicket() is bounded
                // (≤ TacticalContextProvider::DEFAULT_TOKEN_BUDGET = 1500 tokens) and
                // accounted POST-HOC against the per-turn budget (TOKEN_BUDGET_PER_TURN)
                // via the actual input-token count returned by $ai->cumulativeInputTokens()
                // below — accounting is intentional and not silent.
                $prompt .= "\n\n".ContextBuilder::buildForTicket($ticket);

                // Include most recent AI triage note if available
                $triageNote = $ticket->notes()
                    ->where('note_type', NoteType::AiTriage)
                    ->orderByDesc('noted_at')
                    ->first();

                if ($triageNote) {
                    $body = strip_tags($triageNote->body ?? '');
                    if (strlen($body) > 3000) {
                        $body = substr($body, 0, 3000).' [TRUNCATED]';
                    }
                    $prompt .= "\n\n## AI Triage Assessment\nThe AI triage pipeline previously assessed this ticket:\n".$body;
                }
            }
        } elseif ($conversation->context_type === 'client') {
            $client = $conversation->resolveClient();
            if ($client) {
                $prompt .= "\n\n## Client\nName: {$client->name}";
                if ($client->email) {
                    $prompt .= "\nEmail: {$client->email}";
                }
                if ($client->phone) {
                    $prompt .= "\nPhone: {$client->phone}";
                }

                // Always-injected client environment context (§4.6): composed wiki
                // overview when live, else clients.site_notes — via the shared resolver
                // so chat and triage tell the same story.
                $env = ContextBuilder::clientEnvironmentSection($client);
                if ($env) {
                    $prompt .= "\n\n".$env;
                }
            }
        } else {
            $prompt .= "\n\nNo specific context is set for this conversation. For tool access (ticket search, device queries, email security, M365), open the assistant from a ticket or client page.";
        }

        return $prompt;
    }

    /**
     * Collect image attachments from a ticket (description + notes) and return
     * them as Anthropic content blocks. Capped to avoid context blowout.
     *
     * @return array<int, array<string, mixed>>
     */
    private function buildTicketImageBlocks(Ticket $ticket): array
    {
        $ticket->loadMissing(['attachments', 'notes.attachments']);

        $attachments = $ticket->attachments
            ->concat($ticket->notes->flatMap->attachments)
            ->filter(fn ($a) => $a->isImage())
            ->unique('id')
            ->take(8); // cap — keeps context manageable

        if ($attachments->isEmpty()) {
            return [];
        }

        $service = app(AttachmentService::class);
        $blocks = [];

        foreach ($attachments as $att) {
            $base64 = $service->resizeImageForAi($att);
            if (! $base64) {
                continue;
            }
            $blocks[] = [
                'type' => 'image',
                'source' => [
                    'type' => 'base64',
                    'media_type' => $att->mime_type === 'image/gif' ? 'image/png' : $att->mime_type,
                    'data' => $base64,
                ],
            ];
        }

        return $blocks;
    }

    /**
     * Build Anthropic-format message history from conversation, with truncation.
     * Keeps first user message + most recent messages, drops middle in pairs
     * to preserve Anthropic's strict user/assistant alternation requirement.
     */
    private function buildMessageHistory(AssistantConversation $conversation): array
    {
        $messages = $conversation->messages()->orderBy('id')->get();

        // Build Anthropic-format messages
        $apiMessages = [];
        foreach ($messages as $msg) {
            $apiMessages[] = [
                'role' => $msg->role,
                'content' => $msg->content,
            ];
        }

        if (empty($apiMessages)) {
            return [];
        }

        // Truncation: keep first message + most recent, drop middle in pairs
        // (user+assistant) to preserve Anthropic alternation rules
        $totalChars = array_sum(array_map(fn ($m) => strlen($m['content']), $apiMessages));

        if ($totalChars > self::MAX_HISTORY_CHARS && count($apiMessages) > 3) {
            $first = $apiMessages[0];
            $rest = array_slice($apiMessages, 1);

            // Remove oldest messages in pairs to preserve alternation
            $restChars = array_sum(array_map(fn ($m) => strlen($m['content']), $rest));
            $charBudget = self::MAX_HISTORY_CHARS - strlen($first['content']);

            while ($restChars > $charBudget && count($rest) > 2) {
                // Remove two messages (a user+assistant or assistant+user pair)
                $removed1 = array_shift($rest);
                $restChars -= strlen($removed1['content']);

                if (count($rest) > 1) {
                    $removed2 = array_shift($rest);
                    $restChars -= strlen($removed2['content']);
                }
            }

            $apiMessages = array_merge([$first], $rest);
        }

        return array_values($apiMessages);
    }
}
