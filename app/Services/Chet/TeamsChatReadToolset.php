<?php

namespace App\Services\Chet;

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

    /** @return array<int, array<string, mixed>> */
    public static function definitions(): array
    {
        return [
            [
                'name' => 'list_teams_chats',
                'description' => 'List Teams chats that the configured teammate-chet bot is a member of.',
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
                'description' => 'List members for a Teams chat only after verifying the configured teammate-chet bot is a member.',
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
                'description' => 'Read recent Teams chat messages only after verifying the configured teammate-chet bot is a member.',
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

        try {
            $chats = app(GraphClient::class)->getAllPages(
                'users/'.$botId.'/chats',
                ['$top' => min($limit, 50), '$orderby' => 'lastMessagePreview/createdDateTime desc'],
                1,
            );
        } catch (\Throwable $e) {
            Log::warning('[ChetDataSurface] Teams chat discovery failed', ['error' => $e->getMessage()]);

            return ['error' => 'Teams chat query failed: '.mb_substr($e->getMessage(), 0, 200)];
        }

        $visible = [];
        $membershipErrors = 0;

        foreach ($chats as $chat) {
            if (count($visible) >= $limit) {
                break;
            }

            $chatId = $this->chatIdFromArray($chat);
            if ($chatId === null) {
                continue;
            }

            try {
                $members = $this->membersForChat($chatId);
            } catch (\Throwable $e) {
                $membershipErrors++;
                Log::warning('[ChetDataSurface] Teams chat membership check failed during discovery', [
                    'chat_id' => $chatId,
                    'error' => $e->getMessage(),
                ]);

                continue;
            }

            if ($this->botIsMember($members)) {
                $visible[] = $this->sanitizeChat($chat);
            }
        }

        return [
            'count' => count($visible),
            'filtered_by_bot_membership' => true,
            'membership_check_errors' => $membershipErrors,
            'chats' => $visible,
        ];
    }

    private function getMembers(array $input): array
    {
        $chatId = $this->chatIdFromInput($input);
        if ($chatId === null) {
            return ['error' => 'chat_id is required'];
        }

        try {
            $members = $this->membersForChat($chatId);
        } catch (\Throwable $e) {
            Log::warning('[ChetDataSurface] Teams chat members query failed', ['chat_id' => $chatId, 'error' => $e->getMessage()]);

            return ['error' => 'Teams chat query failed: '.mb_substr($e->getMessage(), 0, 200)];
        }

        if (! $this->botIsMember($members)) {
            return ['error' => "Teams chat '{$chatId}' denied: bot is not a member"];
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

        try {
            $members = $this->membersForChat($chatId);
        } catch (\Throwable $e) {
            Log::warning('[ChetDataSurface] Teams chat history membership check failed', ['chat_id' => $chatId, 'error' => $e->getMessage()]);

            return ['error' => 'Teams chat query failed: '.mb_substr($e->getMessage(), 0, 200)];
        }

        if (! $this->botIsMember($members)) {
            return ['error' => "Teams chat '{$chatId}' denied: bot is not a member"];
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

    private function botIsMember(array $members): bool
    {
        $botId = $this->botAppId();
        if ($botId === null) {
            return false;
        }

        $botId = mb_strtolower($botId);

        foreach ($members as $member) {
            foreach ($this->memberIdentityCandidates($member) as $candidate) {
                if (mb_strtolower($candidate) === $botId) {
                    return true;
                }
            }
        }

        return false;
    }

    /** @return array<int, string> */
    private function memberIdentityCandidates(array $member): array
    {
        $candidates = [];

        foreach (['userId', 'applicationId', 'appId', 'teamsAppId'] as $key) {
            if (isset($member[$key]) && is_scalar($member[$key])) {
                $candidates[] = trim((string) $member[$key]);
            }
        }

        foreach ([
            ['identity', 'application', 'id'],
            ['identity', 'user', 'id'],
            ['application', 'id'],
            ['teamsApp', 'id'],
            ['app', 'id'],
        ] as $path) {
            $value = $member;
            foreach ($path as $segment) {
                if (! is_array($value) || ! array_key_exists($segment, $value)) {
                    $value = null;
                    break;
                }
                $value = $value[$segment];
            }

            if (is_scalar($value)) {
                $candidates[] = trim((string) $value);
            }
        }

        return array_values(array_filter($candidates, fn (string $candidate): bool => $candidate !== ''));
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
            'last_message_preview' => $chat['lastMessagePreview'] ?? null,
        ];
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
            'body' => $this->plainText($message['body']['content'] ?? ''),
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

        return mb_substr($text, 0, 4000);
    }

    private function botAppId(): ?string
    {
        $botId = TeamsBotConfig::appId();

        return $botId !== null && ! str_contains($botId, '/') ? $botId : null;
    }

    private function chatIdFromInput(array $input): ?string
    {
        $chatId = trim((string) ($input['chat_id'] ?? ''));

        return $chatId !== '' && ! str_contains($chatId, '/') ? $chatId : null;
    }

    private function chatIdFromArray(array $chat): ?string
    {
        $chatId = trim((string) ($chat['id'] ?? ''));

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
