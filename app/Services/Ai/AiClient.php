<?php

namespace App\Services\Ai;

use App\Support\AiConfig;
use GuzzleHttp\Client as GuzzleClient;
use Illuminate\Support\Facades\Log;

class AiClient
{
    private GuzzleClient $http;

    private int $cumulativeInputTokens = 0;

    private int $cumulativeOutputTokens = 0;

    /**
     * $http is a test seam, mirroring TacticalClient: production always passes null
     * and gets the real client. It exists so the tool loop can be driven end-to-end
     * against a MockHandler — the schema-enforcement guard below is only meaningful
     * if a test can prove the executor was never REACHED, which needs the real loop.
     */
    public function __construct(?string $modelOverride = null, ?GuzzleClient $http = null)
    {
        $this->http = $http ?? new GuzzleClient(['timeout' => 120]);
        $this->modelOverride = $modelOverride;
    }

    private ?string $modelOverride;

    // ── Simple Completion ──

    /**
     * Send a simple completion request. Works with both Anthropic and OpenAI.
     * $userMessage can be a string or an array of Anthropic content blocks (for multimodal).
     */
    public function complete(string $system, string|array $userMessage, int $maxTokens = 4096): AiResponse
    {
        $provider = AiConfig::provider();
        $apiKey = AiConfig::get('api_key');
        $model = $this->modelOverride ?? AiConfig::model();

        if ($provider === 'anthropic') {
            return $this->callAnthropic($apiKey, $model, $system, [
                ['role' => 'user', 'content' => $userMessage],
            ], $maxTokens);
        }

        // OpenAI doesn't support image blocks — fall back to text only
        $text = is_array($userMessage)
            ? implode("\n", array_map(fn ($b) => $b['type'] === 'text' ? $b['text'] : '[image]', $userMessage))
            : $userMessage;

        return $this->callOpenAi($apiKey, $model, $system, [
            ['role' => 'user', 'content' => $text],
        ], $maxTokens);
    }

    /**
     * Complete and parse the response as JSON. Handles code fence stripping.
     *
     * @return array<string, mixed>
     *
     * @throws \RuntimeException if JSON parsing fails
     */
    public function completeJson(string $system, string $userMessage, int $maxTokens = 2048): array
    {
        $response = $this->complete($system, $userMessage, $maxTokens);

        return $this->parseJson($response->text);
    }

    /**
     * Simple yes/no confirmation. Returns true if AI says YES.
     * Defaults to false on failure (safe side).
     */
    public function confirmYesNo(string $prompt): bool
    {
        try {
            $response = $this->complete(
                'You are a classification assistant. Respond with ONLY "YES" or "NO".',
                $prompt,
                10,
            );

            return str_starts_with(strtoupper(trim($response->text)), 'YES');
        } catch (\Throwable $e) {
            Log::warning('[AiClient] confirmYesNo failed, defaulting to NO', [
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    // ── Agentic Tool Loop (Anthropic only) ──

    /**
     * Run an agentic tool-use loop with Claude (single user message).
     *
     * @param  string  $system  System prompt
     * @param  string|array  $userMessage  Initial user message (string or array of content blocks for multimodal)
     * @param  array  $tools  Claude tool_use format tool definitions
     * @param  callable  $executor  fn(string $toolName, array $input): mixed
     * @param  int  $maxRounds  Maximum tool-use rounds
     * @param  int  $maxTokenBudget  Max total tokens (input+output) across all rounds
     * @param  int  $wallClockSeconds  Max wall-clock time for the loop
     * @return AiResponse Final text response from the model
     */
    public function runToolLoop(
        string $system,
        string|array $userMessage,
        array $tools,
        callable $executor,
        int $maxRounds = 10,
        int $maxTokenBudget = 200_000,
        int $wallClockSeconds = 240,
    ): AiResponse {
        $messages = [['role' => 'user', 'content' => $userMessage]];

        return $this->executeToolLoop($system, $messages, $tools, $executor, $maxRounds, $maxTokenBudget, $wallClockSeconds);
    }

    /**
     * Run an agentic tool-use loop with Claude using full conversation history.
     * Used for multi-turn chat (e.g., AI assistant).
     *
     * @param  string  $system  System prompt
     * @param  array  $messages  Full Anthropic-format conversation history
     * @param  array  $tools  Claude tool_use format tool definitions
     * @param  callable  $executor  fn(string $toolName, array $input): mixed
     * @param  int  $maxRounds  Maximum tool-use rounds
     * @param  int  $maxTokenBudget  Max total tokens (input+output) across all rounds
     * @param  int  $wallClockSeconds  Max wall-clock time for the loop
     * @param  callable|null  $onToolCall  fn(string $toolName): void — progress callback
     * @param  bool  $enableCaching  Whether to enable prompt caching for the system prompt
     */
    public function runChatWithTools(
        string $system,
        array $messages,
        array $tools,
        callable $executor,
        int $maxRounds = 10,
        int $maxTokenBudget = 200_000,
        int $wallClockSeconds = 120,
        ?callable $onToolCall = null,
        bool $enableCaching = false,
    ): AiResponse {
        return $this->executeToolLoop($system, $messages, $tools, $executor, $maxRounds, $maxTokenBudget, $wallClockSeconds, $onToolCall, $enableCaching);
    }

    /**
     * Shared tool loop implementation. Extracted from runToolLoop.
     */
    private function executeToolLoop(
        string $system,
        array $messages,
        array $tools,
        callable $executor,
        int $maxRounds,
        int $maxTokenBudget,
        int $wallClockSeconds,
        ?callable $onToolCall = null,
        bool $enableCaching = false,
    ): AiResponse {
        if (AiConfig::provider() !== 'anthropic') {
            throw new \RuntimeException('Tool loop is only supported with Anthropic provider');
        }

        $apiKey = AiConfig::get('api_key');
        $model = $this->modelOverride ?? AiConfig::model();
        $startTime = microtime(true);
        $loopTokens = 0;

        // psa-ejzjd — the ONLY names this loop may dispatch, derived from the schema
        // actually delivered to the model. Dispatch is BY NAME, so without this the
        // published schema is documentation rather than a boundary: a surface that
        // hardened itself by filtering its schema stayed fully exploitable through its
        // executor. That defect was found and fixed per-surface five times before being
        // closed here once (psa-uw2o, psa-hbbuq, psa-hryjm, psa-vydpz, psa-o8w6t).
        //
        // Computed ONCE, before the loop, from the same $tools array that is sent every
        // round: $tools is by-value and never mutated, so this cannot drift from what the
        // model was offered. Deriving it a second time from a live source is exactly the
        // TOCTOU that cost psa-uw2o four review rounds — do not "refresh" it per round.
        $publishedTools = array_column($tools, 'name');

        $finalText = '';

        for ($round = 0; $round < $maxRounds; $round++) {
            // Check wall-clock timer
            $elapsed = microtime(true) - $startTime;
            if ($elapsed > $wallClockSeconds) {
                Log::warning('[AiClient] Tool loop wall-clock limit reached', [
                    'round' => $round,
                    'elapsed_seconds' => round($elapsed),
                ]);
                break;
            }

            // Check token budget
            if ($loopTokens > $maxTokenBudget) {
                Log::warning('[AiClient] Tool loop token budget exceeded', [
                    'round' => $round,
                    'tokens_used' => $loopTokens,
                    'budget' => $maxTokenBudget,
                ]);
                break;
            }

            $response = $this->callAnthropic($apiKey, $model, $system, $messages, 4096, $tools, $enableCaching);
            $loopTokens += $response->totalTokens();

            // Collect text from this response
            if ($response->text !== '') {
                $finalText .= ($finalText !== '' ? "\n" : '').$response->text;
            }

            // If no tool calls, we're done
            if (! $response->hasToolCalls()) {
                break;
            }

            // Build assistant message with all content blocks (text + tool_use)
            $assistantContent = $this->buildAssistantContent($response);
            $messages[] = ['role' => 'assistant', 'content' => $assistantContent];

            // Execute tools and build tool result message
            $toolResults = [];
            foreach ($response->toolCalls as $toolCall) {
                $toolName = $toolCall['name'];
                $toolInput = $toolCall['input'] ?? [];
                $toolId = $toolCall['id'];

                // psa-ejzjd — refuse anything this turn did not publish, BEFORE the
                // executor is reached. Fail-closed: the refusal goes back to the model as
                // a tool_result so the loop continues and it can correct itself, rather
                // than throwing, which would turn a hallucinated name into a failed turn.
                //
                // This is deliberately ABOVE $onToolCall: a refused tool never ran, so it
                // must not appear in the caller's progress UI as though it did.
                if (! in_array($toolName, $publishedTools, true)) {
                    Log::warning('[AiClient] Refused a tool that was not published to the model', [
                        'tool' => $toolName,
                        'round' => $round + 1,
                        'published_count' => count($publishedTools),
                    ]);

                    $toolResults[] = [
                        'type' => 'tool_result',
                        'tool_use_id' => $toolId,
                        // Phrased as availability, not as an accusation: the common cause is
                        // benign prompt/schema drift (the system prompt names vendor tool
                        // families unconditionally, while the schema is config-gated), not an
                        // attack. Naming the tool keeps the log diagnosable either way.
                        'content' => json_encode([
                            'error' => "Tool '{$toolName}' is not available in this deployment.",
                        ]),
                    ];

                    continue;
                }

                Log::debug('[AiClient] Executing tool', [
                    'tool' => $toolName,
                    'round' => $round + 1,
                ]);

                if ($onToolCall) {
                    $onToolCall($toolName);
                }

                try {
                    $result = $executor($toolName, $toolInput);
                    $resultStr = is_string($result) ? $result : json_encode($result, JSON_PRETTY_PRINT);

                    // Truncate oversized results
                    if (strlen($resultStr) > 50_000) {
                        $resultStr = substr($resultStr, 0, 50_000)."\n\n[TRUNCATED — result exceeded 50,000 characters]";
                    }
                } catch (\Throwable $e) {
                    $resultStr = json_encode(['error' => $e->getMessage()]);
                    Log::warning('[AiClient] Tool execution failed', [
                        'tool' => $toolName,
                        'error' => $e->getMessage(),
                    ]);
                }

                $toolResults[] = [
                    'type' => 'tool_result',
                    'tool_use_id' => $toolId,
                    'content' => $resultStr,
                ];
            }

            $messages[] = ['role' => 'user', 'content' => $toolResults];
        }

        return new AiResponse(
            text: $finalText,
            inputTokens: $this->cumulativeInputTokens,
            outputTokens: $this->cumulativeOutputTokens,
            stopReason: 'end_turn',
        );
    }

    // ── Token Tracking ──

    public function cumulativeInputTokens(): int
    {
        return $this->cumulativeInputTokens;
    }

    public function cumulativeOutputTokens(): int
    {
        return $this->cumulativeOutputTokens;
    }

    public function cumulativeTotalTokens(): int
    {
        return $this->cumulativeInputTokens + $this->cumulativeOutputTokens;
    }

    public function resetTokenCounters(): void
    {
        $this->cumulativeInputTokens = 0;
        $this->cumulativeOutputTokens = 0;
    }

    // ── Provider-Specific Calls ──

    private function callAnthropic(
        string $apiKey,
        string $model,
        string $system,
        array $messages,
        int $maxTokens,
        array $tools = [],
        bool $enableCaching = false,
    ): AiResponse {
        // Sanitize inputs to prevent json_encode failures from malformed UTF-8
        $system = $this->sanitizeUtf8($system);
        $messages = $this->sanitizeMessages($messages);

        $body = [
            'model' => $model,
            'max_tokens' => $maxTokens,
            'messages' => $messages,
        ];

        // Prompt caching: format system as content block with cache_control
        if ($enableCaching) {
            $body['system'] = [
                ['type' => 'text', 'text' => $system, 'cache_control' => ['type' => 'ephemeral']],
            ];
        } else {
            $body['system'] = $system;
        }

        if ($tools) {
            $body['tools'] = $tools;
        }

        $headers = [
            'x-api-key' => $apiKey,
            'anthropic-version' => '2023-06-01',
            'Content-Type' => 'application/json',
        ];

        try {
            $response = $this->http->post('https://api.anthropic.com/v1/messages', [
                'headers' => $headers,
                'json' => $body,
            ]);
        } catch (\GuzzleHttp\Exception\ClientException $e) {
            $responseBody = (string) $e->getResponse()->getBody();
            Log::error('[AiClient] Anthropic API error', [
                'status' => $e->getResponse()->getStatusCode(),
                'body' => $responseBody,
                'message_count' => count($messages),
                'tool_count' => count($tools),
            ]);

            throw $e;
        }

        $data = json_decode((string) $response->getBody(), true);

        $text = '';
        $toolCalls = [];

        foreach ($data['content'] ?? [] as $block) {
            if ($block['type'] === 'text') {
                $text .= $block['text'];
            } elseif ($block['type'] === 'tool_use') {
                $toolCalls[] = [
                    'id' => $block['id'],
                    'name' => $block['name'],
                    // Preserve as-is; buildAssistantContent forces object serialization
                    'input' => $block['input'] ?? [],
                ];
            }
        }

        $usage = $data['usage'] ?? [];
        $inputTokens = $usage['input_tokens'] ?? 0;
        $outputTokens = $usage['output_tokens'] ?? 0;

        $this->cumulativeInputTokens += $inputTokens;
        $this->cumulativeOutputTokens += $outputTokens;

        Log::debug('[AiClient] Anthropic call', [
            'model' => $model,
            'input_tokens' => $inputTokens,
            'output_tokens' => $outputTokens,
            'stop_reason' => $data['stop_reason'] ?? 'unknown',
            'tool_calls' => count($toolCalls),
        ]);

        return new AiResponse(
            text: trim($text),
            inputTokens: $inputTokens,
            outputTokens: $outputTokens,
            stopReason: $data['stop_reason'] ?? 'end_turn',
            toolCalls: $toolCalls,
        );
    }

    private function callOpenAi(
        string $apiKey,
        string $model,
        string $system,
        array $messages,
        int $maxTokens,
    ): AiResponse {
        // Sanitize inputs to prevent json_encode failures from malformed UTF-8
        $system = $this->sanitizeUtf8($system);
        $messages = $this->sanitizeMessages($messages);

        $allMessages = array_merge(
            [['role' => 'system', 'content' => $system]],
            $messages,
        );

        $response = $this->http->post('https://api.openai.com/v1/chat/completions', [
            'headers' => [
                'Authorization' => "Bearer {$apiKey}",
                'Content-Type' => 'application/json',
            ],
            'json' => [
                'model' => $model,
                'max_tokens' => $maxTokens,
                'messages' => $allMessages,
            ],
        ]);

        $data = json_decode((string) $response->getBody(), true);
        $text = $data['choices'][0]['message']['content'] ?? '';

        $usage = $data['usage'] ?? [];
        $inputTokens = $usage['prompt_tokens'] ?? 0;
        $outputTokens = $usage['completion_tokens'] ?? 0;

        $this->cumulativeInputTokens += $inputTokens;
        $this->cumulativeOutputTokens += $outputTokens;

        Log::debug('[AiClient] OpenAI call', [
            'model' => $model,
            'input_tokens' => $inputTokens,
            'output_tokens' => $outputTokens,
        ]);

        return new AiResponse(
            text: trim($text),
            inputTokens: $inputTokens,
            outputTokens: $outputTokens,
            stopReason: $data['choices'][0]['finish_reason'] ?? 'stop',
        );
    }

    // ── UTF-8 Sanitization ──

    /**
     * Strip invalid UTF-8 byte sequences from text to prevent json_encode failures.
     */
    private function sanitizeUtf8(string $text): string
    {
        return mb_convert_encoding($text, 'UTF-8', 'UTF-8');
    }

    /**
     * Recursively sanitize all string values in a messages array.
     */
    private function sanitizeMessages(array $messages): array
    {
        return array_map(function ($message) {
            if (is_array($message)) {
                return $this->sanitizeMessages($message);
            }

            return is_string($message) ? $this->sanitizeUtf8($message) : $message;
        }, $messages);
    }

    // ── JSON Parsing ──

    /**
     * Parse JSON from AI response text. Handles code fences and preamble text.
     *
     * @return array<string, mixed>
     */
    private function parseJson(string $text): array
    {
        $text = trim($text);

        // 1. Try raw decode first
        $decoded = json_decode($text, true);
        if (is_array($decoded)) {
            return $decoded;
        }

        // 2. Strip markdown code fences
        if (preg_match('/```(?:json)?\s*([\s\S]*?)```/', $text, $matches)) {
            $decoded = json_decode(trim($matches[1]), true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }

        // 3. Try extracting first { ... } block
        $firstBrace = strpos($text, '{');
        $lastBrace = strrpos($text, '}');
        if ($firstBrace !== false && $lastBrace !== false && $lastBrace > $firstBrace) {
            $jsonCandidate = substr($text, $firstBrace, $lastBrace - $firstBrace + 1);
            $decoded = json_decode($jsonCandidate, true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }

        Log::warning('[AiClient] Failed to parse JSON from AI response', [
            'response_preview' => substr($text, 0, 200),
        ]);

        throw new \RuntimeException('Failed to parse JSON from AI response');
    }

    // ── Helper: Build assistant content for tool loop ──

    private function buildAssistantContent(AiResponse $response): array
    {
        $content = [];

        if ($response->text !== '') {
            $content[] = ['type' => 'text', 'text' => $response->text];
        }

        foreach ($response->toolCalls as $toolCall) {
            $content[] = [
                'type' => 'tool_use',
                'id' => $toolCall['id'],
                'name' => $toolCall['name'],
                // Force object serialization — Anthropic rejects [] (array) for input
                'input' => $toolCall['input'] ?: (object) [],
            ];
        }

        return $content;
    }
}
