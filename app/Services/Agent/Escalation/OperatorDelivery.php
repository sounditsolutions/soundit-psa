<?php

namespace App\Services\Agent\Escalation;

use App\Models\User;
use App\Services\EmailService;
use App\Services\Teams\TeamsBotClient;
use App\Services\Technician\Notify\TeamsNotifier;
use App\Services\Technician\Notify\TeamsText;
use App\Services\Wiki\Mining\WikiRedactor;
use App\Support\TeamsBotConfig;
use Illuminate\Support\Facades\Log;

class OperatorDelivery
{
    public const TEAMS_SAFE_TEXT_LIMIT = 12000;

    private const FRAGMENT_LIMIT = 500;

    private const CHUNK_HEADER_RESERVE = 64;

    private const MIN_CHUNK_TEXT_LIMIT = 1000;

    public function __construct(
        private readonly TeamsBotClient $bot,
        private readonly TeamsNotifier $teamsWebhook,
        private readonly EmailService $email,
        private readonly WikiRedactor $redactor,
    ) {}

    public function sanitize(string $fragment, string $placeholder = '[message detail withheld - see the cockpit]'): string
    {
        $fragment = mb_substr($fragment, 0, self::FRAGMENT_LIMIT);

        return $this->scanAndEscape($fragment, $placeholder, 'Fragment');
    }

    public function sanitizeMessage(string $message, string $placeholder = '[message detail withheld - see the cockpit]'): string
    {
        return $this->scanAndEscape($message, $placeholder, 'Message');
    }

    private function scanAndEscape(string $text, string $placeholder, string $label): string
    {
        if ($this->redactor->scan($text) !== []) {
            Log::warning("[OperatorDelivery] {$label} failed output scan - detail withheld");

            return TeamsText::escape($placeholder);
        }

        return TeamsText::escape($text);
    }

    public function send(
        ?User $recipient,
        ?string $conversationId,
        ?string $serviceUrl,
        string $subject,
        string $body,
    ): OperatorDeliveryResult {
        $postedToChat = false;
        $posted = false;

        if (TeamsBotConfig::enabled() && $conversationId !== null && $serviceUrl !== null) {
            try {
                $mentions = [];
                $mentionPrefix = '';

                if ($recipient?->microsoft_id !== null) {
                    $member = $this->bot->getConversationMember($serviceUrl, $conversationId, $recipient->microsoft_id);
                    if ($member !== null && isset($member['id'])) {
                        $mentionName = TeamsText::escape($recipient->name);
                        $mentionName = $mentionName !== '' ? $mentionName : 'operator';
                        $mentions = [['mentionId' => $member['id'], 'name' => $mentionName]];
                        $mentionPrefix = "<at>{$mentionName}</at> ";
                    }
                }

                $postedToChat = $this->sendBotChunks($serviceUrl, $conversationId, $body, $mentions, $mentionPrefix);
                $posted = $postedToChat;
            } catch (\Throwable $e) {
                Log::warning('[OperatorDelivery] Bot send failed - falling back to email only', [
                    'error' => $e->getMessage(),
                ]);
            }
        } else {
            try {
                $posted = $this->sendWebhookChunks($subject, $body);
            } catch (\Throwable $e) {
                Log::warning('[OperatorDelivery] Webhook post failed', [
                    'error' => $e->getMessage(),
                ]);
            }
        }

        if ($recipient?->email !== null && $recipient->email !== '') {
            try {
                $this->email->sendNew($recipient->email, $subject, $body, null, null, null);
            } catch (\Throwable $e) {
                Log::warning('[OperatorDelivery] Email delivery failed', [
                    'user_id' => $recipient->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return new OperatorDeliveryResult(
            posted: $posted,
            postedToChat: $postedToChat,
            remoteMessageId: null,
        );
    }

    /**
     * @param  array<int, array{mentionId: string, name: string}>  $mentions
     */
    private function sendBotChunks(
        string $serviceUrl,
        string $conversationId,
        string $body,
        array $mentions,
        string $mentionPrefix,
    ): bool {
        $posted = true;
        $postBodies = $this->postBodies($body, $mentionPrefix);

        foreach ($postBodies as $index => $postBody) {
            $posted = $this->bot->sendMessageWithMentions(
                $serviceUrl,
                $conversationId,
                $postBody,
                $index === 0 ? $mentions : [],
            ) && $posted;
        }

        return $posted;
    }

    private function sendWebhookChunks(string $subject, string $body): bool
    {
        $posted = true;

        foreach ($this->postBodies($body) as $postBody) {
            $posted = $this->teamsWebhook->post($subject, $postBody) && $posted;
        }

        return $posted;
    }

    /** @return array<int, string> */
    private function postBodies(string $body, string $firstPrefix = ''): array
    {
        $textLimit = max(
            self::MIN_CHUNK_TEXT_LIMIT,
            self::TEAMS_SAFE_TEXT_LIMIT - mb_strlen($firstPrefix) - self::CHUNK_HEADER_RESERVE,
        );
        $chunks = $this->splitText($body, $textLimit);
        $total = count($chunks);

        return array_map(
            fn (string $chunk, int $index): string => ($index === 0 ? $firstPrefix : '')
                .($total > 1 ? '['.($index + 1)."/{$total}] " : '')
                .$chunk,
            $chunks,
            array_keys($chunks),
        );
    }

    /** @return array<int, string> */
    private function splitText(string $text, int $limit): array
    {
        if (mb_strlen($text) <= $limit) {
            return [$text];
        }

        $chunks = [];
        $remaining = $text;

        while (mb_strlen($remaining) > $limit) {
            $window = mb_substr($remaining, 0, $limit + 1);
            $splitAt = $this->splitPosition($window, $limit);
            $chunk = mb_substr($remaining, 0, $splitAt);

            if ($chunk === '') {
                $chunk = mb_substr($remaining, 0, $limit);
                $splitAt = $limit;
            }

            $chunks[] = $chunk;
            $remaining = mb_substr($remaining, $splitAt);
        }

        if ($remaining !== '') {
            $chunks[] = $remaining;
        }

        return $chunks;
    }

    private function splitPosition(string $window, int $limit): int
    {
        $preferredFloor = (int) floor($limit * 0.6);

        foreach (["\n\n", "\n", '. ', '! ', '? ', '; ', ': ', ', '] as $boundary) {
            $position = mb_strrpos($window, $boundary);
            if ($position !== false && $position >= $preferredFloor && $position <= $limit) {
                return $position + mb_strlen($boundary);
            }
        }

        $position = mb_strrpos(mb_substr($window, 0, $limit), ' ');
        if ($position !== false && $position > 0) {
            return $position + 1;
        }

        return $limit;
    }
}
