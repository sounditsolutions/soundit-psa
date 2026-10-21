<?php

namespace App\Services\Chet;

use App\Models\OperatorInbox;
use App\Models\Setting;
use App\Services\Graph\GraphClient;
use App\Support\TeamsBotConfig;
use Illuminate\Support\Facades\Log;

class TeamsChatReadToolset
{
    private const PLAIN_TEXT_INPUT_LIMIT = 12_000;

    private const TOOL_NAMES = [
        'list_teams_chats',
        'get_teams_chat_members',
        'get_teams_chat_history',
        'teams_search_channel',
        self::ATTACHMENT_TOOL,
    ];

    public const ATTACHMENT_TOOL = 'get_teams_message_attachment';

    /**
     * The Graph application permission a hosted-content read needs (Microsoft
     * Learn, chatmessagehostedcontent-get and chatmessage-get, v1.0: Application
     * = Chat.Read.All least privileged, Chat.ReadWrite.All higher).
     */
    public const HOSTED_CONTENT_PERMISSION = 'Chat.Read.All';

    /** Graph's page size for chat messages is capped at 50. */
    private const SEARCH_PAGE_SIZE = 50;

    /** Bounded page walk: at most this many pages (250 messages) per search. */
    public const SEARCH_MAX_PAGES = 5;

    private const SEARCH_MAX_RESULTS = 25;

    public function __construct(
        private readonly ChetDataSurfaceTextSanitizer $textSanitizer,
    ) {}

    /** @return array<int, array<string, mixed>> */
    public static function definitions(): array
    {
        return [
            [
                'name' => 'list_teams_chats',
                'description' => 'List Teams chats that the configured Teams bot is known to be in from durable PSA state.',
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
            [
                'name' => 'teams_search_channel',
                'description' => 'Search recent messages in a known Teams chat (the same history get_teams_chat_history reads, including your own posts) for a case-insensitive substring. Read-only: no ack, no cursor, nothing is marked read. chat_or_channel is "operator", "escalation", or a chat id get_teams_chat_history accepts. The walk is bounded to the newest '.(self::SEARCH_PAGE_SIZE * self::SEARCH_MAX_PAGES).' messages: history_exhausted false means older messages exist that were NOT searched, so no match is not proof the text was never posted. Matching runs on the same redacted plain text that is returned. Returns {chat_id, query, scanned, history_exhausted, count, messages}, newest first.',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'chat_or_channel' => ['type' => 'string', 'description' => '"operator", "escalation", or a known Microsoft Graph chat ID.'],
                        'query' => ['type' => 'string', 'description' => 'Substring to find (case-insensitive, at least 2 characters).'],
                        'limit' => ['type' => 'integer', 'description' => 'Maximum matches to return (default 10, max '.self::SEARCH_MAX_RESULTS.').'],
                    ],
                    'required' => ['chat_or_channel', 'query'],
                ],
            ],
            [
                'name' => self::ATTACHMENT_TOOL,
                'description' => 'See one image from a Teams chat message. Messages from get_teams_chat_history, teams_search_channel and poll_operator_messages carry attachments: [{attachment_id, kind, filename, mime_type, size_bytes}] and an "[image N]" / "[file N]" marker (filename is the sender\'s text and comes inside an untrusted fence). Pass the message\'s chat id (or "operator" / "escalation"), its message id (poll_operator_messages: graph_chat_id and graph_message_id) and the attachment_id. Only chats known from durable PSA state are readable. An inline image is returned downscaled under the same byte and pixel ceilings as get_ticket_attachment: over the staff MCP route as an MCP image content block after the JSON text ({attachment_id, filename, media_type, is_image, image_block: true}, no data_base64); elsewhere base64-encoded ({attachment_id, filename, media_type, is_image, data_base64}); a non-image, oversize or undecodable image is refused. File attachments (kind file) are not fetched; ask the operator to paste it as an image or attach it to a ticket. If poll_operator_messages listed this attachment_id for the message and Teams now reports the message as edited, or with a different number of images or files than the poll recorded, the fetch is refused rather than risk returning a different image, whichever tool the attachment_id was read from; ask the operator to paste the image again. The fetch is also refused before anything is read from Teams, whichever tool the ids came from, when an operator-inbox row in this chat is withheld under poll_operator_messages\' current rule and either carries this message id or carries no message id and is not provably more than '.TeamsMessageAttachmentFetcher::UNLINKED_MARGIN_SECONDS.' seconds older than the message; ask the operator to resend what you need.',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'chat_id' => ['type' => 'string', 'description' => 'Microsoft Graph chat ID, or "operator" / "escalation".'],
                        'message_id' => ['type' => 'string', 'description' => 'Microsoft Graph chat message ID.'],
                        'attachment_id' => ['type' => 'string', 'description' => 'attachment_id from the message\'s attachments list, e.g. "inline-1".'],
                    ],
                    'required' => ['chat_id', 'message_id', 'attachment_id'],
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
            'teams_search_channel' => $this->searchChannel($input),
            self::ATTACHMENT_TOOL => app(TeamsMessageAttachmentFetcher::class)->fetch(
                $this->resolveChatAlias($input['chat_id'] ?? null),
                $input,
                fn (string $chatId): bool => $this->isKnownConversation($chatId),
            ),
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

    /**
     * teams_search_channel (card 5sALzgSC). Same gate and same sanitizer as
     * getHistory(); the only differences are a bounded multi-page walk and a
     * substring filter. Strictly a read: no OperatorInbox/ack/cursor writes.
     */
    private function searchChannel(array $input): array
    {
        $chatId = $this->resolveChatAlias($input['chat_or_channel'] ?? null);
        if ($chatId === null) {
            return ['error' => 'chat_or_channel is required and must name a configured or known Teams chat'];
        }

        if (! $this->isKnownConversation($chatId)) {
            return ['error' => "Teams chat '{$chatId}' denied: not a known Teams conversation"];
        }

        $query = is_scalar($input['query'] ?? null) ? trim((string) $input['query']) : '';
        if (mb_strlen($query) < 2) {
            return ['error' => 'query must be at least 2 characters'];
        }
        $limit = is_numeric($input['limit'] ?? null)
            ? min(max(1, (int) $input['limit']), self::SEARCH_MAX_RESULTS)
            : 10;

        try {
            $messages = app(GraphClient::class)->getAllPages(
                "chats/{$chatId}/messages",
                ['$top' => self::SEARCH_PAGE_SIZE, '$orderby' => 'createdDateTime desc'],
                self::SEARCH_MAX_PAGES,
            );
        } catch (\Throwable $e) {
            Log::warning('[ChetDataSurface] Teams chat search query failed', ['chat_id' => $chatId, 'error' => $e->getMessage()]);

            return ['error' => 'Teams chat query failed: '.mb_substr($e->getMessage(), 0, 200)];
        }

        $cap = self::SEARCH_PAGE_SIZE * self::SEARCH_MAX_PAGES;
        $messages = array_slice($messages, 0, $cap);
        $needle = mb_strtolower($query);
        $matches = [];

        foreach ($messages as $message) {
            if (count($matches) >= $limit) {
                break;
            }
            if (! is_array($message)) {
                continue;
            }
            $haystack = $this->textSanitizer->sanitizedText($this->plainText($message['body']['content'] ?? ''), 4000);
            if (str_contains(mb_strtolower($haystack), $needle)) {
                $matches[] = $this->sanitizeMessage($message);
            }
        }

        return [
            'chat_id' => $chatId,
            'query' => $query,
            'scanned' => count($messages),
            // A full final page means older history may exist beyond the cap.
            'history_exhausted' => count($messages) < $cap,
            'count' => count($matches),
            'messages' => $matches,
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
            'topic' => $this->textSanitizer->sanitizeNullable('Teams chat topic', $chat['topic'] ?? null, 300),
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

        foreach ([
            'subject' => ['Teams chat last message preview subject', 300],
            'summary' => ['Teams chat last message preview summary', 500],
        ] as $field => [$label, $maxChars]) {
            if (array_key_exists($field, $preview)) {
                $preview[$field] = $this->textSanitizer->sanitizeNullable($label, $preview[$field], $maxChars);
            }
        }

        if (array_key_exists('from', $preview)) {
            $preview['from'] = $this->sanitizeLastMessagePreviewFrom($preview['from']);
        }

        return $preview;
    }

    private function sanitizeLastMessagePreviewFrom(mixed $from): mixed
    {
        if (! is_array($from)) {
            return $from;
        }

        foreach (['user', 'application', 'device'] as $type) {
            if (isset($from[$type]) && is_array($from[$type]) && array_key_exists('displayName', $from[$type])) {
                $from[$type]['displayName'] = $this->textSanitizer->sanitizeNullable(
                    'Teams chat last message preview sender display name',
                    $from[$type]['displayName'],
                    200,
                );
            }
        }

        return $from;
    }

    private function sanitizeMember(array $member): array
    {
        return [
            'id' => $member['id'] ?? null,
            'display_name' => $this->textSanitizer->sanitizeNullable('Teams chat member display name', $member['displayName'] ?? null, 200),
            'user_id' => $member['userId'] ?? $member['identity']['user']['id'] ?? null,
            'application_id' => $member['applicationId'] ?? $member['identity']['application']['id'] ?? null,
            'email' => $member['email'] ?? null,
            'tenant_id' => $member['tenantId'] ?? null,
            'roles' => $member['roles'] ?? [],
        ];
    }

    /** "operator" / "escalation" alias, or a literal chat id; null when neither. */
    private function resolveChatAlias(mixed $requested): ?string
    {
        $requested = is_scalar($requested) ? trim((string) $requested) : '';

        return match (strtolower($requested)) {
            'operator' => $this->normalizeChatId(TeamsBotConfig::chetConversationId()),
            'escalation' => $this->normalizeChatId(TeamsBotConfig::escalationConversationId()),
            default => $this->normalizeChatId($requested),
        };
    }

    private function sanitizeMessage(array $message): array
    {
        $refs = TeamsMessageAttachments::fromGraphMessage($message);
        $markers = TeamsMessageAttachments::markers($refs);
        $body = $this->textSanitizer->sanitize(
            'Teams chat message body',
            $this->plainText($message['body']['content'] ?? ''),
            4000,
        );

        return [
            'id' => $message['id'] ?? null,
            'created_at' => $message['createdDateTime'] ?? null,
            'last_modified_at' => $message['lastModifiedDateTime'] ?? null,
            'message_type' => $message['messageType'] ?? null,
            'importance' => $message['importance'] ?? null,
            'subject' => $this->textSanitizer->sanitizeNullable('Teams chat message subject', $message['subject'] ?? null, 300),
            'from' => $this->sanitizeMessageFrom($message['from'] ?? null),
            'body_content_type' => $message['body']['contentType'] ?? null,
            // Our markers sit OUTSIDE the untrusted fence: a marker inside it
            // could be typed by the sender, so they carry only kind and ordinal.
            // They are what keeps an image-only message from reading as an
            // empty one; attachments carries the refs, filenames fenced.
            'body' => $markers === null ? $body : 'Attachments: '.$markers."\n".$body,
            'attachments' => TeamsMessageAttachments::fencedRefs($refs, $this->textSanitizer),
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
                    'display_name' => $this->textSanitizer->sanitizeNullable(
                        'Teams chat message sender display name',
                        $from[$type]['displayName'] ?? null,
                        200,
                    ),
                ];
            }
        }

        return null;
    }

    private function plainText(mixed $value): string
    {
        $input = mb_substr((string) $value, 0, self::PLAIN_TEXT_INPUT_LIMIT);
        $text = html_entity_decode(strip_tags($input), ENT_QUOTES | ENT_HTML5, 'UTF-8');
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
