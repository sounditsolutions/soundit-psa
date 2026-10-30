<?php

namespace App\Services\Chet;

use App\Enums\OperatorMessageCategory;
use App\Models\OperatorInbox;
use App\Models\SignalDelivery;
use App\Models\SignalInboxEntry;
use App\Models\Ticket;
use App\Models\User;
use App\Services\Agent\Escalation\OperatorDelivery;
use App\Services\Teams\TeamsBotClient;
use App\Services\Technician\Notify\TeamsText;
use App\Services\Technician\PromptFence;
use App\Support\TeamsBotConfig;
use App\Support\TeamsPersonaConfig;
use App\Support\TechnicianConfig;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

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
            'teams_post_message' => $this->teamsPostMessage($input, $tokenLabel),
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
            if ($ticket?->isUnverifiedContactIntake()) {
                return ['error' => 'Contact intake requires staff verification.'];
            }
        }

        $recipientId = TechnicianConfig::operatorRecipientFor($category);
        $recipient = $recipientId ? User::find($recipientId) : null;

        $trimmedLabel = trim((string) $tokenLabel);
        $persona = $trimmedLabel !== '' ? TeamsPersonaConfig::byTokenLabel($trimmedLabel) : null;

        $actorName = $persona?->display_name ?? TechnicianConfig::aiActorName();
        $actorLabel = TeamsText::escape($actorName);
        $sanitized = $this->delivery->sanitizeMessageWithMeta(
            $this->stripTrailingPersonaSignatures($message, $actorName),
        );
        $safeMessage = $sanitized['text'];
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
            $sanitized['meta'],
        );

        return [
            'posted' => $result->posted,
            'remote_message_id' => $result->remoteMessageId,
            ...$result->scanReceipt(),
        ];
    }

    /**
     * teams_post_message (card 5sALzgSC): a teammate's message, not an alarm.
     *
     * Differs from postToOperator() in exactly the ways the card asks, and no
     * other: no TeamsText::escape over the body (markdown survives), no email
     * (this path never calls OperatorDelivery::send(), whose trailing sendNew()
     * is the email), no category prefix and no subject, and an @mention only
     * when mention === true. What it KEEPS from postToOperator(): the persona is
     * derived from the authenticated token label, never from input; the output
     * scan withholds the whole body on a hit; untrusted ticket fields are
     * escaped at their interpolation point; unverified contact intake is refused.
     *
     * The target is a closed allowlist (TeamsPostTargets) and an unknown or
     * unconfigured target is refused before anything is sent. There is no
     * webhook fallback: a post that cannot reach the bot reports posted:false.
     *
     * @return array<string, mixed>
     */
    private function teamsPostMessage(array $input, ?string $tokenLabel = null): array
    {
        $trimmedLabel = trim((string) $tokenLabel);
        $persona = $trimmedLabel !== '' ? TeamsPersonaConfig::byTokenLabel($trimmedLabel) : null;

        $requested = is_scalar($input['chat_or_channel'] ?? null) ? (string) $input['chat_or_channel'] : '';
        $target = TeamsPostTargets::resolve($requested, $persona);
        if ($target === null) {
            return ['error' => 'chat_or_channel is not an allowed Teams target. Allowed: '.implode(', ', TeamsPostTargets::keys($persona)).'. Nothing was posted.'];
        }
        if ($target['service_url'] === null) {
            return ['error' => "Teams target '{$target['key']}' has no service URL configured. Nothing was posted."];
        }
        if ($persona === null && ! TeamsBotConfig::enabled() && ! TeamsBotConfig::chetRoutingEnabled()) {
            return ['error' => 'No Teams bot lane is enabled. Nothing was posted.'];
        }

        $rawBody = is_string($input['body'] ?? null) ? $input['body'] : '';
        if (trim($rawBody) === '') {
            return ['error' => 'body is required'];
        }
        if (mb_strlen($rawBody) > OperatorDelivery::TEAMS_SAFE_TEXT_LIMIT) {
            return ['error' => 'body exceeds '.OperatorDelivery::TEAMS_SAFE_TEXT_LIMIT.' characters; split it into several posts. Nothing was posted.'];
        }

        $mentionRequested = ($input['mention'] ?? false) === true;

        $ticket = null;
        if (isset($input['ticket_id']) && is_numeric($input['ticket_id']) && (int) $input['ticket_id'] > 0) {
            $ticket = Ticket::with('client')->find((int) $input['ticket_id']);
            if ($ticket === null) {
                return ['error' => 'Ticket not found. Nothing was posted.'];
            }
            if ($ticket->isUnverifiedContactIntake()) {
                return ['error' => 'Contact intake requires staff verification.'];
            }
        }

        // Trim only the ends: interior blank lines and indentation are the
        // agent's formatting and must reach Teams intact.
        $actorName = $persona?->display_name ?? TechnicianConfig::aiActorName();
        $message = $this->stripTrailingPersonaSignatures(trim($rawBody), $actorName);
        // The policy runs first and its output is scanned too (as $sent), so a
        // neutralization can never assemble a string the scan did not see.
        $policy = TeamsMarkdownPolicy::apply($message);
        $scanned = $this->delivery->scanMessageWithMeta($message, sent: $policy['text']);
        $text = $scanned['text'];
        // Withheld: none of the body's markdown is posted, so nothing counts.
        $neutralized = $scanned['meta']->withheld
            ? array_fill_keys(array_keys($policy['neutralized']), 0)
            : $policy['neutralized'];

        if ($ticket !== null) {
            $client = TeamsText::escape($ticket->client?->name ?? '');
            $subject = TeamsText::escape($ticket->subject ?? '');
            $text .= "\n\nTicket #{$ticket->id} ({$client} - {$subject})";
        }

        $bot = $persona !== null ? app(TeamsBotClient::class)->forPersona($persona) : app(TeamsBotClient::class);

        $mentions = [];
        if ($mentionRequested) {
            $recipientId = TechnicianConfig::operatorRecipientFor(OperatorMessageCategory::Escalation);
            $recipient = $recipientId ? User::find($recipientId) : null;
            if ($recipient?->microsoft_id !== null) {
                $member = $bot->getConversationMember($target['service_url'], $target['conversation_id'], $recipient->microsoft_id);
                if ($member !== null && isset($member['id'])) {
                    $name = TeamsText::escape($recipient->name);
                    $name = $name !== '' ? $name : 'operator';
                    $mentions = [['mentionId' => (string) $member['id'], 'name' => $name]];
                    $text = "<at>{$name}</at> ".$text;
                }
            }
        }

        try {
            $sent = $bot->postMarkdownMessage($target['service_url'], $target['conversation_id'], $text, $mentions);
        } catch (\Throwable $e) {
            Log::warning('[OperatorBridge] teams_post_message send failed', ['target' => $target['key'], 'error' => $e->getMessage()]);
            $sent = ['posted' => false, 'id' => null];
        }

        return [
            'posted' => $sent['posted'],
            'remote_message_id' => $sent['id'],
            'target' => $target['key'],
            'mentioned' => $mentions !== [],
            'markdown_neutralized' => $neutralized,
            ...$scanned['meta']->toArray(),
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

        $messages = $rows->map(function (OperatorInbox $row): array {
            $meta = $this->textSanitizer->inboxRowPromptMeta($row);

            // Delivery facts ride OUTSIDE the fenced text — a marker inside
            // the fence would be spoofable by the operator message itself.
            //
            // Three states, and they must never be conflated:
            //  - WITHHELD: the body is a placeholder, not a prefix. It is
            //    never "truncated" — truncated invites the reader to go get
            //    the rest, and the rest is precisely what the scan refused.
            //    A row is withheld now (poll-side scan) or was withheld at
            //    ingest, which the ingest path recorded in text_withheld at
            //    write time. Never inferred by comparing the body to the
            //    placeholder: that literal is public and an operator can
            //    send it verbatim, so matching on it would report a
            //    delivered message as unrecoverable — and it would derive a
            //    delivery fact from the fenced text, which is the one thing
            //    the rule above forbids.
            //  - TRUNCATED: a real incomplete prefix, from the poll cap or
            //    from an ingest-side cut (recorded original length exceeds
            //    the storage cap) — never by comparing lengths across
            //    redaction.
            //  - UNKNOWN (null): a pre-column legacy row (text_chars null).
            //    These are the T-22782 rows, cut at the old 2000 cap with
            //    nothing recording it. false would be an affirmative claim
            //    of completeness we cannot support, so the field says
            //    unknown and text_total_chars stays null — the stored
            //    length is not the original length and must not stand in
            //    for it.
            //    inboxRowPromptMeta() folds text_withheld into withheld; the
            //    attachment fetcher refuses on the same call (#4887).
            $withheld = $meta['withheld'];
            $ingestTruncated = $row->text_chars !== null
                && $row->text_chars > OperatorBridgeTextSanitizer::MAX_STORAGE_CHARS;

            $truncated = match (true) {
                $withheld => false,
                $meta['truncated'] || $ingestTruncated => true,
                $row->text_chars === null => null,
                default => false,
            };

            // Attachment refs (card 2Cj3kOsy). null = captured before refs
            // were recorded (unknown), [] = none. The marker is ours, sits
            // OUTSIDE the fence and carries no sender text; the filename is
            // sender text, fenced and redacted as get_teams_chat_history does.
            //
            // A WITHHELD row offers no way to its attachments (#4879): no
            // refs, no marker and no Graph ids, so an image cannot carry
            // what the text pipeline refused. attachments_withheld says only
            // whether refs were recorded (null = unknown, legacy row).
            if ($withheld) {
                $refs = null;
                $markers = null;
            } else {
                $refs = is_array($row->attachments)
                    ? TeamsMessageAttachments::fencedRefs($row->attachments, app(ChetDataSurfaceTextSanitizer::class))
                    : null;
                $markers = $refs === null ? null : TeamsMessageAttachments::markers($refs);
            }
            $fenced = $this->promptFence->fence('operator message', $meta['text']);

            $ids = $withheld
                ? [
                    'graph_chat_id' => null,
                    'graph_message_id' => null,
                    'attachments_withheld' => is_array($row->attachments) ? $row->attachments !== [] : null,
                ]
                : [
                    // What get_teams_message_attachment takes as chat_id / message_id.
                    'graph_chat_id' => $row->conversation_id,
                    'graph_message_id' => $row->activity_id,
                ];

            return [
                'id' => $row->id,
                'conversation_id' => $row->conversation_id,
                'sender_user_id' => $row->sender_user_id,
                'sender_name' => $row->sender?->name,
                'text' => $markers === null ? $fenced : 'Attachments: '.$markers."\n".$fenced,
                'attachments' => $refs,
                ...$ids,
                'text_withheld' => $withheld,
                // An observed poll-side replacement is positive evidence;
                // otherwise preserve the nullable ingest fact, not a body marker.
                'text_redacted' => $meta['redacted'] ? true : $row->text_redacted,
                'text_truncated' => $truncated,
                'text_total_chars' => $row->text_chars,
                'ts' => $row->ts?->toIso8601String(),
                'direct_mention' => (bool) $row->direct_mention,
                'authorized_steer' => (bool) $row->authorized_steer,
            ];
        })->all();

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

            // inbox_id is the DELIVERY (one per matching route/step/destination for this
            // token label); event_id is the OCCURRENCE (signal_events.id), the only
            // correct dedupe key for a consumer. Card qptZ5IKH.
            $signal = [
                'inbox_id' => $row->id,
                'event_id' => (int) $row->event_id,
                'event' => $payload['event'] ?? null,
                'entity' => $payload['entity'] ?? null,
                'category' => $payload['category'] ?? null,
                'occurred_at' => $payload['occurred_at'] ?? null,
            ];

            // asset.watch_fired rows (card K3VEcxtw) carry the observation behind
            // the fire; other rows are unchanged.
            if (isset($payload['watch']) && is_array($payload['watch'])) {
                $signal['watch'] = $payload['watch'];
            }

            // intake.webform_* rows (card JyBDeHPz) carry ticket_id, age_seconds and review_url.
            foreach (['ticket_id', 'age_seconds', 'review_url'] as $key) {
                if (array_key_exists($key, $payload)) {
                    $signal[$key] = $payload[$key];
                }
            }

            return $signal;
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
