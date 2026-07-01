<?php

namespace App\Services\Chet;

use App\Enums\OperatorMessageCategory;
use App\Models\OperatorInbox;
use App\Models\Ticket;
use App\Models\User;
use App\Services\Agent\Escalation\OperatorDelivery;
use App\Services\Technician\Notify\TeamsText;
use App\Services\Technician\PromptFence;
use App\Support\TeamsBotConfig;
use App\Support\TechnicianConfig;

class OperatorBridgeToolExecutor
{
    public function __construct(
        private readonly OperatorDelivery $delivery,
        private readonly OperatorBridgeTextSanitizer $textSanitizer,
        private readonly PromptFence $promptFence,
    ) {}

    /** @return array<string, mixed> */
    public function execute(string $name, array $input): array
    {
        return match ($name) {
            'find_staff' => $this->findStaff($input),
            'get_staff' => $this->getStaff($input),
            'post_to_operator' => $this->postToOperator($input),
            'poll_operator_messages' => $this->pollOperatorMessages($input),
            default => ['error' => "Unknown tool: {$name}"],
        };
    }

    /** @return array<string, mixed> */
    private function findStaff(array $input): array
    {
        $query = trim((string) ($input['query'] ?? ''));
        if ($query === '') {
            return ['error' => 'query is required'];
        }

        $limit = max(1, min((int) ($input['limit'] ?? 10), 25));

        $staff = User::query()
            ->where(fn ($w) => $w
                ->where('name', 'like', "%{$query}%")
                ->orWhere('email', 'like', "%{$query}%"))
            ->orderByDesc('is_active')
            ->orderBy('name')
            ->limit($limit)
            ->get(['id', 'name', 'email', 'microsoft_id', 'is_active']);

        return [
            'staff' => $staff->map(fn (User $u): array => $this->serializeStaff($u))->all(),
        ];
    }

    /** @return array<string, mixed> */
    private function getStaff(array $input): array
    {
        $id = $input['id'] ?? null;
        if (! is_numeric($id) || (int) $id <= 0) {
            return ['error' => 'id is required'];
        }

        $user = User::query()->find((int) $id, ['id', 'name', 'email', 'microsoft_id', 'is_active']);
        if ($user === null) {
            return ['error' => 'Staff user not found'];
        }

        return $this->serializeStaff($user);
    }

    /** @return array<string, mixed> */
    private function postToOperator(array $input): array
    {
        $category = OperatorMessageCategory::tryFrom(trim((string) ($input['category'] ?? '')));
        if ($category === null) {
            $valid = implode(', ', array_map(fn (OperatorMessageCategory $c) => $c->value, OperatorMessageCategory::cases()));

            return ['error' => "category must be one of: {$valid}"];
        }

        $message = trim((string) ($input['message'] ?? ''));
        if ($message === '') {
            return ['error' => 'message is required'];
        }

        $ticket = null;
        if (isset($input['ticket_id']) && is_numeric($input['ticket_id']) && (int) $input['ticket_id'] > 0) {
            $ticket = Ticket::with('client')->find((int) $input['ticket_id']);
        }

        $recipientId = TechnicianConfig::operatorRecipientFor($category);
        $recipient = $recipientId ? User::find($recipientId) : null;

        $safeMessage = $this->delivery->sanitizeMessage($message);

        $persona = TeamsText::escape(TechnicianConfig::aiActorName());
        $label = $category->label();
        $prefix = "{$persona} - {$label}";
        if ($ticket !== null) {
            $client = TeamsText::escape($ticket->client?->name ?? '');
            $subject = TeamsText::escape($ticket->subject ?? '');
            $prefix .= " on #{$ticket->id} ({$client} - {$subject})";
        }

        $body = "{$prefix}: {$safeMessage}";
        $subject = $ticket !== null
            ? "{$persona} - {$label} - ticket #{$ticket->id}"
            : "{$persona} - {$label}";

        $result = $this->delivery->send(
            $recipient,
            TeamsBotConfig::chetConversationId(),
            TeamsBotConfig::escalationServiceUrl(),
            $subject,
            $body,
        );

        return [
            'posted' => $result->posted,
            'remote_message_id' => $result->remoteMessageId,
        ];
    }

    /** @return array<string, mixed> */
    private function pollOperatorMessages(array $input): array
    {
        $conversationId = TeamsBotConfig::chetConversationId();
        $cursor = isset($input['cursor']) && is_numeric($input['cursor']) ? (int) $input['cursor'] : 0;

        if ($conversationId === null) {
            return ['messages' => [], 'next_cursor' => (string) $cursor];
        }

        if ($cursor > 0) {
            OperatorInbox::query()
                ->where('conversation_id', $conversationId)
                ->where('id', '<=', $cursor)
                ->whereNull('delivered_at')
                ->update(['delivered_at' => now()]);
        }

        $rows = OperatorInbox::with('sender:id,name')
            ->where('conversation_id', $conversationId)
            ->whereNull('delivered_at')
            ->orderBy('id')
            ->limit(50)
            ->get();

        $messages = $rows->map(fn (OperatorInbox $row): array => [
            'id' => $row->id,
            'conversation_id' => $row->conversation_id,
            'sender_user_id' => $row->sender_user_id,
            'sender_name' => $row->sender?->name,
            'text' => $this->promptFence->fence(
                'operator message',
                $this->textSanitizer->sanitizeForPrompt($row->text),
            ),
            'ts' => $row->ts?->toIso8601String(),
            'direct_mention' => (bool) $row->direct_mention,
            'authorized_steer' => (bool) $row->authorized_steer,
        ])->all();

        return [
            'messages' => $messages,
            'next_cursor' => $rows->isNotEmpty() ? (string) $rows->last()->id : (string) $cursor,
        ];
    }

    /** @return array{id:int, name:string|null, email:string|null, microsoft_id:string|null, is_active:bool} */
    private function serializeStaff(User $user): array
    {
        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'microsoft_id' => $user->microsoft_id,
            'is_active' => (bool) $user->is_active,
        ];
    }
}
