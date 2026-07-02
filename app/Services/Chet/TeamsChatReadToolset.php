<?php

namespace App\Services\Chet;

use App\Models\OperatorInbox;
use App\Models\Setting;
use App\Services\Graph\GraphClient;
use App\Support\TeamsBotConfig;
use Illuminate\Support\Facades\Log;

class TeamsChatReadToolset
{
    private const TOOL_NAMES = [
        'list_teams_chats',
        'get_teams_chat_members',
        'get_teams_chat_history',
    ];

    public function __construct(
        private readonly ChetDataSurfaceTextSanitizer $textSanitizer,
    ) {}

    /** @return array<int, array<string, mixed>> */
    public static function definitions(): array
    {
        return [
            [
                'name' => 'list_teams_chats',
                'description' => 'List Teams chats that the configured teammate-chet bot is known to be in from durable PSA state.',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'limit' => ['type' => 'integer', 'description' => 'Maximum chats to return (default 20, max 50).'],
                    ],
                    'required' => [],
                ],
            ],
            [
                'name' => 'get_teams_chat_members',
                'description' => 'List members for a Teams chat only after verifying the chat is known from durable PSA state.',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'chat_id' => ['type' => 'string', 'description' => 'Microsoft Graph chat ID.'],
                    ],
                    'required' => ['chat_id'],
                ],
            ],
            [
                'name' => 'get_teams_chat_history',
                'description' => 'Read recent Teams chat messages only after verifying the chat is known from durable PSA state.',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'chat_id' => ['type' => 'string', 'description' => 'Microsoft Graph chat ID.'],
                        'limit' => ['type' => 'integer', 'description' => 'Maximum messages to return (default 20, max 50).'],
                    ],
                    'required' => ['chat_id'],
                ],
            ],
        ];
    }

    public static function handles(string $toolName): bool
    {
        return in_array($toolName, self::TOOL_NAMES, true);
    }

    public function execute(string $toolName, array $input): array
    {
        return match ($toolName) {
            'list_teams_chats' => $this->listChats($input),
            'get_teams_chat_members' => $this->getMembers($input),
            'get_teams_chat_history' => $this->getHistory($input),
            default => ['error' => "Unknown tool: {$toolName}"],
        };
    }

    private function listChats(array $input): array
    {
        $botId = $this->botAppId();
        if ($botId === null) {
            return ['error' => 'Teams bot app ID is not configured'];
        }

        $limit = $this->boundedLimit($input['limit'] ?? null, 20);
        $knownChatIds = $this->knownConversationIds();

        $visible = [];
        $chatFetchErrors = 0;

        foreach ($knownChatIds as $chatId) {
            if (count($visible) >= $limit) {
                break;
            }

            try {
                $chat = app(GraphClient::class)->get("chats/{$chatId}");
            } catch (\Throwable $e) {
                $chatFetchErrors++;
                Log::warning('[ChetDataSurface] Teams known chat lookup failed during discovery', [
                    'chat_id' => $chatId,
                    'error' => $e->getMessage(),
                ]);

                continue;
            }

            if (is_array($chat)) {
                $chat['id'] ??= $chatId;
                $visible[] = $this->sanitizeChat($chat);
            }
        }

        return [
            'count' => count($visible),
            'filtered_by_known_conversations' => true,
            'filtered_by_bot_membership' => false,
            'chat_fetch_errors' => $chatFetchErrors,
            'membership_check_errors' => 0,
            'chats' => $visible,
        ];
    }

    private function getMembers(array $input): array
    {
        $chatId = $this->chatIdFromInput($input);
        if ($chatId === null) {
            return ['error' => 'chat_id is required'];
        }

        if (! $this->isKnownConversation($chatId)) {
            return ['error' => "Teams chat '{$chatId}' denied: not a known Teams conversation"];
        }

        try {
            $members = $this->membersForChat($chatId);
        } catch (\Throwable $e) {
            Log::warning('[ChetDataSurface] Teams chat members query failed', ['chat_id' => $chatId, 'error' => $e->getMessage()]);

            return ['error' => 'Teams chat query failed: '.mb_substr($e->getMessage(), 0, 200)];
        }

        return [
            'chat_id' => $chatId,
            'count' => count($members),
            'members' => array_map(fn ($member) => $this->sanitizeMember($member), $members),
        ];
    }

    private function getHistory(array $input): array
    {
        $chatId = $this->chatIdFromInput($input);
        if ($chatId === null) {
            return ['error' => 'chat_id is required'];
        }

        if (! $this->isKnownConversation($chatId)) {
            return ['error' => "Teams chat '{$chatId}' denied: not a known Teams conversation"];
        }

        $limit = $this->boundedLimit($input['limit'] ?? null, 20);

        try {
            $messages = app(GraphClient::class)->getAllPages(
                "chats/{$chatId}/messages",
                ['$top' => $limit, '$orderby' => 'createdDateTime desc'],
                1,
            );
        } catch (\Throwable $e) {
            Log::warning('[ChetDataSurface] Teams chat history query failed', ['chat_id' => $chatId, 'error' => $e->getMessage()]);

            return ['error' => 'Teams chat query failed: '.mb_substr($e->getMessage(), 0, 200)];
        }

        $messages = array_slice($messages, 0, $limit);

        return [
            'chat_id' => $chatId,
            'count' => count($messages),
            'messages' => array_map(fn ($message) => $this->sanitizeMessage($message), $messages),
        ];
    }

    /** @return array<int, array<string, mixed>> */
    private function membersForChat(string $chatId): array
    {
        return app(GraphClient::class)->getAllPages("chats/{$chatId}/members", [], 2);
    }

    /** @return array<int, string> */
    private function knownConversationIds(): array
    {
        $ids = [];

        foreach ([TeamsBotConfig::chetConversationId(), TeamsBotConfig::escalationConversationId()] as $id) {
            if (($id = $this->normalizeChatId($id)) !== null) {
                $ids[] = $id;
            }
        }

        $inboxIds = OperatorInbox::query()
            ->select('conversation_id')
            ->where('conversation_id', '!=', '')
            ->when($this->conversationContextChangedAt(), fn ($query, string $changedAt) => $query->where('created_at', '>', $changedAt))
            ->groupBy('conversation_id')
            ->orderByRaw('MAX(id) DESC')
            ->pluck('conversation_id')
            ->all();

        foreach ($inboxIds as $id) {
            if (($id = $this->normalizeChatId($id)) !== null) {
                $ids[] = $id;
            }
        }

        return array_values(array_unique($ids));
    }

    private function conversationContextChangedAt(): ?string
    {
        return Setting::query()
            ->whereIn('key', [
                'teams_bot_app_id',
                'teams_bot_tenant_id',
                'teams_chet_conversation_id',
                'teams_escalation_conversation_id',
            ])
            ->max('updated_at');
    }

    private function isKnownConversation(string $chatId): bool
    {
        return in_array($chatId, $this->knownConversationIds(), true);
    }

    private function sanitizeChat(array $chat): array
    {
        return [
            'id' => (string) ($chat['id'] ?? ''),
            'topic' => $chat['topic'] ?? null,
            'chat_type' => $chat['chatType'] ?? null,
            'tenant_id' => $chat['tenantId'] ?? null,
            'created_at' => $chat['createdDateTime'] ?? null,
            'last_updated_at' => $chat['lastUpdatedDateTime'] ?? null,
            'web_url' => $chat['webUrl'] ?? null,
            'last_message_preview' => $this->sanitizeLastMessagePreview($chat['lastMessagePreview'] ?? null),
        ];
    }

    private function sanitizeLastMessagePreview(mixed $preview): mixed
    {
        if (! is_array($preview)) {
            return $preview;
        }

        $bodyContent = $preview['body']['content'] ?? null;
        if (is_scalar($bodyContent)) {
            $preview['body']['content'] = $this->textSanitizer->sanitize(
                'Teams chat last message preview body',
                $this->plainText($bodyContent),
                1000,
            );
        }

        return $preview;
    }

    private function sanitizeMember(array $member): array
    {
        return [
            'id' => $member['id'] ?? null,
            'display_name' => $member['displayName'] ?? null,
            'user_id' => $member['userId'] ?? $member['identity']['user']['id'] ?? null,
            'application_id' => $member['applicationId'] ?? $member['identity']['application']['id'] ?? null,
            'email' => $member['email'] ?? null,
            'tenant_id' => $member['tenantId'] ?? null,
            'roles' => $member['roles'] ?? [],
        ];
    }

    private function sanitizeMessage(array $message): array
    {
        return [
            'id' => $message['id'] ?? null,
            'created_at' => $message['createdDateTime'] ?? null,
            'last_modified_at' => $message['lastModifiedDateTime'] ?? null,
            'message_type' => $message['messageType'] ?? null,
            'importance' => $message['importance'] ?? null,
            'subject' => $message['subject'] ?? null,
            'from' => $this->sanitizeMessageFrom($message['from'] ?? null),
            'body_content_type' => $message['body']['contentType'] ?? null,
            'body' => $this->textSanitizer->sanitize(
                'Teams chat message body',
                $this->plainText($message['body']['content'] ?? ''),
                4000,
            ),
        ];
    }

    private function sanitizeMessageFrom(mixed $from): ?array
    {
        if (! is_array($from)) {
            return null;
        }

        foreach (['user', 'application', 'device'] as $type) {
            if (isset($from[$type]) && is_array($from[$type])) {
                return [
                    'type' => $type,
                    'id' => $from[$type]['id'] ?? null,
                    'display_name' => $from[$type]['displayName'] ?? null,
                ];
            }
        }

        return null;
    }

    private function plainText(mixed $value): string
    {
        $text = html_entity_decode(strip_tags((string) $value), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace('/\s+/u', ' ', trim($text)) ?? '';

        return $text;
    }

    private function botAppId(): ?string
    {
        $botId = TeamsBotConfig::appId();

        return $botId !== null && ! str_contains($botId, '/') ? $botId : null;
    }

    private function chatIdFromInput(array $input): ?string
    {
        return $this->normalizeChatId($input['chat_id'] ?? null);
    }

    private function normalizeChatId(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $chatId = trim((string) $value);

        return $chatId !== '' && ! str_contains($chatId, '/') ? $chatId : null;
    }

    private function boundedLimit(mixed $value, int $default): int
    {
        if (! is_numeric($value)) {
            return $default;
        }

        return min(max(1, (int) $value), 50);
    }
}
