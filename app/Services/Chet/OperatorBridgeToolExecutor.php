<?php

namespace App\Services\Chet;

use App\Enums\OperatorMessageCategory;
use App\Models\OperatorInbox;
use App\Models\SignalDelivery;
use App\Models\SignalInboxEntry;
use App\Models\Ticket;
use App\Models\User;
use App\Services\Agent\Escalation\OperatorDelivery;
use App\Services\Technician\Notify\TeamsText;
use App\Services\Technician\PromptFence;
use App\Support\TeamsBotConfig;
use App\Support\TeamsPersonaConfig;
use App\Support\TechnicianConfig;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

class OperatorBridgeToolExecutor
{
    public function __construct(
        private readonly OperatorDelivery $delivery,
        private readonly OperatorBridgeTextSanitizer $textSanitizer,
        private readonly PromptFence $promptFence,
    ) {}

    /** @return array<string, mixed> */
    public function execute(string $name, array $input, ?string $tokenLabel = null): array
    {
        return match ($name) {
            'find_staff' => $this->findStaff($input),
            'get_staff' => $this->getStaff($input),
            'post_to_operator' => $this->postToOperator($input, $tokenLabel),
            'poll_operator_messages' => $this->pollOperatorMessages($input, $tokenLabel),
            'poll_signals' => $this->pollSignals($input, $tokenLabel),
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

    /**
     * $tokenLabel is the AUTHENTICATED McpStaffToken's label (see McpStaffController),
     * never anything from $input — the persona is derived server-side from it, the
     * SAME trust boundary Task 2 established for inbound (signed-aud -> personaKey).
     * An absent or unrecognized label resolves NO persona (TeamsPersonaConfig::
     * byTokenLabel() is active()-scoped, per psa-7drx T1 — enabled=true AND
     * credential-complete) and this falls back byte-identical to the pre-P1
     * legacy actor/targets — never a cross-persona leak.
     *
     * @return array<string, mixed>
     */
    private function postToOperator(array $input, ?string $tokenLabel = null): array
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

        $trimmedLabel = trim((string) $tokenLabel);
        $persona = $trimmedLabel !== '' ? TeamsPersonaConfig::byTokenLabel($trimmedLabel) : null;

        $actorName = $persona?->display_name ?? TechnicianConfig::aiActorName();
        $actorLabel = TeamsText::escape($actorName);
        $safeMessage = $this->delivery->sanitizeMessage(
            $this->stripTrailingPersonaSignatures($message, $actorName),
        );
        $label = $category->label();
        $ticketContext = null;
        if ($ticket !== null) {
            $client = TeamsText::escape($ticket->client?->name ?? '');
            $subject = TeamsText::escape($ticket->subject ?? '');
            $ticketContext = "#{$ticket->id} ({$client} - {$subject})";
        }

        $body = match (true) {
            $category === OperatorMessageCategory::Reply && $ticketContext !== null => "{$ticketContext}: {$safeMessage}",
            $category === OperatorMessageCategory::Reply => $safeMessage,
            $ticketContext !== null => "{$label} on {$ticketContext}: {$safeMessage}",
            default => "{$label}: {$safeMessage}",
        };

        $subject = $ticket !== null
            ? "{$actorLabel} - {$label} - ticket #{$ticket->id}"
            : "{$actorLabel} - {$label}";

        // conversation_refs shape: ['conversation_id' => string, 'service_url' => string].
        // Missing/partial refs on an enabled persona resolve to null targets, which
        // OperatorDelivery::send()'s own null-guard turns into "no bot post" — dormant-safe.
        $conversationId = $persona !== null
            ? (($persona->conversation_refs ?? [])['conversation_id'] ?? null)
            : TeamsBotConfig::chetConversationId();
        $serviceUrl = $persona !== null
            ? (($persona->conversation_refs ?? [])['service_url'] ?? null)
            : TeamsBotConfig::escalationServiceUrl();

        $result = $this->delivery->send(
            $recipient,
            $conversationId,
            $serviceUrl,
            $subject,
            $body,
            $persona,
        );

        return [
            'posted' => $result->posted,
            'remote_message_id' => $result->remoteMessageId,
        ];
    }

    private function stripTrailingPersonaSignatures(string $message, string $actorName): string
    {
        if ($actorName === '') {
            return $message;
        }

        $quotedActorName = preg_quote($actorName, '/');
        $stripped = preg_replace('/(?:\R\s*)+(?:(?:--|—)\s*'.$quotedActorName.'\s*[.!]?\s*(?:\R\s*)*)+$/iu', '', $message);

        return rtrim($stripped ?? $message);
    }

    /**
     * $tokenLabel is the AUTHENTICATED McpStaffToken's label (see McpStaffController),
     * never anything from $input — the persona LANE is derived server-side from it,
     * the SAME trust boundary postToOperator() uses for outbound. An absent/empty
     * label fails CLOSED (mirrors pollSignals): a scoped poll tool must never
     * silently fall back to an undifferentiated drain.
     *
     * Lane resolution uses TeamsPersonaConfig::byTokenLabelForLane() (enabled()-
     * scoped) rather than byTokenLabel() (active()-scoped) — deliberately looser
     * here (psa-2wis). An ENABLED persona's token ALWAYS resolves to its OWN lane
     * (persona_key), even mid-credential-setup (missing bot_app_id/tenant_id/
     * secret), when its own lane may currently be EMPTY. It must never fall
     * through to the LEGACY lane (persona IS NULL) merely because its credential
     * wizard isn't finished yet — that would drain rows belonging to the pre-P1
     * single-bot operator inbox. Only a label that authenticates but matches NO
     * enabled persona at all resolves the LEGACY lane — byte-identical to the
     * pre-P1 single-lane behavior.
     *
     * @return array<string, mixed>
     */
    private function pollOperatorMessages(array $input, ?string $tokenLabel = null): array
    {
        $tokenLabel = trim((string) $tokenLabel);
        if ($tokenLabel === '') {
            return ['error' => 'poll_operator_messages requires a scoped token'];
        }

        $persona = TeamsPersonaConfig::byTokenLabelForLane($tokenLabel);
        $lane = $persona?->persona_key;

        $cursor = isset($input['cursor']) && is_numeric($input['cursor']) ? max(0, (int) $input['cursor']) : 0;

        if ($cursor > 0) {
            DB::transaction(function () use ($lane, $cursor): void {
                $this->laneScope(OperatorInbox::query(), $lane)
                    ->where('id', '<=', $cursor)
                    ->whereNull('delivered_at')
                    ->update(['delivered_at' => now()]);
            });
        }

        $rows = $this->laneScope(OperatorInbox::with('sender:id,name'), $lane)
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

    /**
     * The SAME lane filter for both the ack UPDATE and the SELECT, so the two
     * can never diverge. A null lane (legacy) MUST use whereNull — `where('persona',
     * null)` compiles to `= NULL`, which matches zero rows in SQL and would
     * silently break the legacy lane rather than draining it.
     *
     * @param  Builder<OperatorInbox>  $query
     * @return Builder<OperatorInbox>
     */
    private function laneScope(Builder $query, ?string $lane): Builder
    {
        return $lane === null ? $query->whereNull('persona') : $query->where('persona', $lane);
    }

    /** @return array<string, mixed> */
    private function pollSignals(array $input, ?string $tokenLabel): array
    {
        $tokenLabel = trim((string) $tokenLabel);
        if ($tokenLabel === '') {
            return ['error' => 'poll_signals requires a scoped token'];
        }

        $cursor = isset($input['cursor']) && is_numeric($input['cursor'])
            ? max(0, (int) $input['cursor'])
            : 0;
        $limit = isset($input['limit']) && is_numeric($input['limit'])
            ? (int) $input['limit']
            : 20;
        $limit = max(1, min($limit, 50));

        if ($cursor > 0) {
            DB::transaction(function () use ($cursor, $tokenLabel): void {
                $rows = SignalInboxEntry::query()
                    ->whereNull('acked_at')
                    ->where('id', '<=', $cursor)
                    ->whereHas('destination', fn ($query) => $query->where('mcp_token_label', $tokenLabel))
                    ->get(['id', 'delivery_id']);

                if ($rows->isEmpty()) {
                    return;
                }

                $ackedAt = now();
                SignalInboxEntry::query()
                    ->whereIn('id', $rows->pluck('id')->all())
                    ->update(['acked_at' => $ackedAt]);

                $deliveryIds = $rows->pluck('delivery_id')->filter()->unique()->values()->all();
                if ($deliveryIds !== []) {
                    SignalDelivery::query()
                        ->whereIn('id', $deliveryIds)
                        ->update(['status' => 'acked', 'acked_at' => $ackedAt]);
                }
            });
        }

        $rows = SignalInboxEntry::query()
            ->whereNull('acked_at')
            ->whereHas('destination', fn ($query) => $query->where('mcp_token_label', $tokenLabel))
            ->orderBy('id')
            ->limit($limit)
            ->get();

        $signals = $rows->map(function (SignalInboxEntry $row): array {
            $payload = is_array($row->payload) ? $row->payload : [];

            return [
                'inbox_id' => $row->id,
                'event' => $payload['event'] ?? null,
                'entity' => $payload['entity'] ?? null,
                'category' => $payload['category'] ?? null,
                'occurred_at' => $payload['occurred_at'] ?? null,
            ];
        })->all();

        return [
            'signals' => $signals,
            'cursor' => $rows->isNotEmpty() ? (int) $rows->max('id') : $cursor,
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
