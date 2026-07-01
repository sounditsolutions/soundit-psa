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
    public function __construct(
        private readonly TeamsBotClient $bot,
        private readonly TeamsNotifier $teamsWebhook,
        private readonly EmailService $email,
        private readonly WikiRedactor $redactor,
    ) {}

    public function sanitize(string $fragment, string $placeholder = '[message detail withheld - see the cockpit]'): string
    {
        $fragment = mb_substr($fragment, 0, 500);

        if ($this->redactor->scan($fragment) !== []) {
            Log::warning('[OperatorDelivery] Fragment failed output scan - detail withheld');

            return TeamsText::escape($placeholder);
        }

        return TeamsText::escape($fragment);
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
                $postBody = $body;

                if ($recipient?->microsoft_id !== null) {
                    $member = $this->bot->getConversationMember($serviceUrl, $conversationId, $recipient->microsoft_id);
                    if ($member !== null && isset($member['id'])) {
                        $mentionName = TeamsText::escape($recipient->name);
                        $mentionName = $mentionName !== '' ? $mentionName : 'operator';
                        $mentions = [['mentionId' => $member['id'], 'name' => $mentionName]];
                        $postBody = "<at>{$mentionName}</at> ".$body;
                    }
                }

                $postedToChat = $this->bot->sendMessageWithMentions($serviceUrl, $conversationId, $postBody, $mentions);
                $posted = $postedToChat;
            } catch (\Throwable $e) {
                Log::warning('[OperatorDelivery] Bot send failed - falling back to email only', [
                    'error' => $e->getMessage(),
                ]);
            }
        } else {
            try {
                $posted = $this->teamsWebhook->post($subject, $body);
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
}
