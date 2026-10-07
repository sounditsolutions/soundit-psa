<?php

namespace App\Services\Chet;

use App\Models\OperatorInbox;
use App\Services\Assistant\AssistantToolExecutor;
use App\Services\AttachmentService;
use App\Services\Graph\GraphClient;
use App\Services\Graph\GraphClientException;
use App\Services\Graph\GraphTokenRefreshFailedException;
use Closure;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * get_teams_message_attachment (card 2Cj3kOsy): one inline image from a known
 * Teams chat, in get_ticket_attachment's return shape and under its ceilings.
 *
 * Read-only: two Graph GETs, no writes anywhere. The caller's attachment_id is
 * our ordinal ("inline-N"); it is resolved against the hosted-content ids in
 * the message Graph returns, so no caller-supplied id reaches a Graph path
 * except the message id, which must be the all-digit Graph id shape.
 *
 * File attachments (contentType reference) are SharePoint/OneDrive links. The
 * Graph permission that reads chats does not read those drives, so they are
 * refused rather than fetched through a new Files grant.
 *
 * poll_operator_messages numbers refs from the Bot Framework activity, this
 * tool from the Graph message; their parity is an assumption. Wherever the
 * inbox recorded that attachment_id for the message, an edit or a per-kind
 * count mismatch refuses rather than return whatever Graph's Nth image now
 * is. The check keys on server state only: a history ordinal is the same
 * "inline-N" string as a poll ordinal, so nothing the caller says about
 * where it came from can skip it.
 *
 * A message is refused outright, before any Graph read, when an inbox row of
 * this chat is withheld under poll_operator_messages' rule evaluated now and
 * that row either carries this message id (#4887) or carries none and is not
 * provably older than the message (#4909), whether or not the row recorded
 * attachments. The chat id and message id are not secrets (conversation_id
 * rides on the withheld poll row, and get_teams_chat_history lists message
 * ids), so the refusal has to key on inbox rows, not on which ids Chet was
 * shown.
 *
 * A row with no activity_id (written before that column existed, or with a
 * non-numeric activity id) cannot be linked to a Graph message, so it is
 * matched by time instead, failing closed: a Teams chat message id is the
 * epoch-millisecond creation time, and the row's ts is the activity
 * timestamp (or the later receive time), so a message is treated as possibly
 * such a withheld row's message unless its id time is more than
 * UNLINKED_MARGIN_SECONDS after the row's ts. That refuses every older
 * message in the chat too: coarse, but it needs no Graph read, so the
 * decision reads only the DB and comes before any Graph request.
 */
class TeamsMessageAttachmentFetcher
{
    private const IMAGE_MIME_TYPES = ['image/png', 'image/jpeg', 'image/gif', 'image/webp'];

    /**
     * How far before a message an unlinked withheld row may be timestamped and
     * still refuse it. ts is stored to the second and falls back to the
     * (later) receive time, so a row's ts sits at or after its message's id
     * time less one second; the margin only has to absorb clock skew.
     */
    public const UNLINKED_MARGIN_SECONDS = 60;

    public const WITHHELD_REFUSAL = "Refused before anything was read from Teams: an operator-inbox row in this chat that is withheld under poll_operator_messages' current rule either carries this message id or carries none and is not provably more than ".self::UNLINKED_MARGIN_SECONDS.' seconds older than this message, so its attachments are not returned. Ask the operator to resend what you need.';

    public function __construct(
        private readonly AttachmentService $attachments,
        private readonly OperatorBridgeTextSanitizer $textSanitizer,
    ) {}

    /**
     * @param  Closure(string): bool  $isKnownConversation  the SAME fence get_teams_chat_history uses
     * @return array<string, mixed>
     */
    public function fetch(?string $chatId, array $input, Closure $isKnownConversation): array
    {
        if ($chatId === null) {
            return ['error' => 'chat_id is required'];
        }

        if (! $isKnownConversation($chatId)) {
            return ['error' => "Teams chat '{$chatId}' denied: not a known Teams conversation"];
        }

        $messageId = is_scalar($input['message_id'] ?? null) ? trim((string) $input['message_id']) : '';
        // No leading zero (#4910): the inbox lookup is an exact string match.
        if (! preg_match('/^[1-9][0-9]{0,31}$/', $messageId)) {
            return ['error' => 'message_id is required (the numeric Teams message id)'];
        }

        $attachmentId = is_scalar($input['attachment_id'] ?? null) ? trim((string) $input['attachment_id']) : '';
        if (! preg_match('/^(inline|file)-[1-9][0-9]{0,2}$/', $attachmentId)) {
            return ['error' => 'attachment_id is required (e.g. "inline-1" from the message\'s attachments list)'];
        }

        if ($this->withheldByPoll($chatId, $messageId) || $this->unlinkedWithheldRowNotOlder($chatId, $messageId)) {
            return ['error' => self::WITHHELD_REFUSAL];
        }

        $graph = app(GraphClient::class);
        $messagePath = "chats/{$chatId}/messages/{$messageId}";

        try {
            $message = $graph->get($messagePath);
        } catch (\Throwable $e) {
            return $this->graphFailure($e, $chatId, 'message');
        }

        $message = is_array($message) ? $message : [];
        $graphRefs = TeamsMessageAttachments::fromGraphMessage($message);

        $mismatch = $this->pollRefsMismatch($chatId, $messageId, $attachmentId, $message, $graphRefs);
        if ($mismatch !== null) {
            return ['error' => $mismatch];
        }

        $ref = null;
        foreach ($graphRefs as $candidate) {
            if ($candidate['attachment_id'] === $attachmentId) {
                $ref = $candidate;
                break;
            }
        }

        // Same-shaped refusal for an absent ordinal and an absent message body ref.
        if ($ref === null || $ref['_vendor_id'] === '') {
            return ['error' => 'Attachment not found on this message'];
        }

        if ($ref['kind'] === 'file') {
            return ['error' => "Attachment {$attachmentId} is a shared file, not an inline image. Shared files live in SharePoint/OneDrive and are not fetchable with this tool's Graph permission; ask the operator to paste it into the chat as an image or attach it to a ticket."];
        }

        try {
            $content = $graph->getRaw($messagePath.'/hostedContents/'.rawurlencode($ref['_vendor_id']).'/$value');
        } catch (\Throwable $e) {
            return $this->graphFailure($e, $chatId, 'hosted content');
        }

        if ($content === null || $content === '') {
            return ['error' => 'Attachment content could not be read from Teams (not found or empty)'];
        }

        // Byte ceiling BEFORE any GD decode (get_ticket_attachment's order).
        if (strlen($content) > AssistantToolExecutor::MAX_ATTACHMENT_BYTES) {
            return ['error' => 'Attachment is too large to return inline ('.strlen($content).' bytes, limit '.AssistantToolExecutor::MAX_ATTACHMENT_BYTES.'); open it in Teams.'];
        }

        // The type comes from the bytes, never from a header or a name.
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->buffer($content) ?: 'application/octet-stream';
        if (! in_array($mime, self::IMAGE_MIME_TYPES, true)) {
            return ['error' => "Attachment is not an image this tool can return ({$mime}); open it in Teams."];
        }

        // Pixel ceiling before GD decode: header-only read (decompression-bomb guard).
        $info = @getimagesizefromstring($content);
        if ($info !== false && ($info[0] * $info[1]) > AssistantToolExecutor::MAX_IMAGE_PIXELS) {
            return ['error' => 'Image dimensions too large to process ('.$info[0].'x'.$info[1].').'];
        }

        $data = $this->attachments->resizeImageBytesForAi($content, $mime);
        if ($data === null || $data === '') {
            return ['error' => 'Attachment could not be decoded as an image'];
        }

        return [
            'attachment_id' => $attachmentId,
            'filename' => $ref['filename'],
            'media_type' => match ($mime) {
                'image/png', 'image/gif' => 'image/png',
                'image/webp' => 'image/webp',
                default => 'image/jpeg',
            },
            'is_image' => true,
            'data_base64' => $data,
        ];
    }

    /**
     * True when any inbox row for this chat and message is withheld in the
     * sense poll_operator_messages uses: the one shared derivation,
     * OperatorBridgeTextSanitizer::inboxRowPromptMeta(), so the two cannot
     * drift. Rows of other chats never count.
     */
    private function withheldByPoll(string $chatId, string $messageId): bool
    {
        return OperatorInbox::query()
            ->where('conversation_id', $chatId)
            ->where('activity_id', $messageId)
            ->get(['id', 'text', 'text_withheld'])
            ->contains(fn (OperatorInbox $row): bool => $this->textSanitizer->inboxRowPromptMeta($row)['withheld']);
    }

    /**
     * True when this chat has a withheld inbox row with no activity_id whose
     * ts is no earlier than UNLINKED_MARGIN_SECONDS before the message's id
     * time (#4909). An id whose epoch-ms reading is more than a day in the
     * future places the message nowhere, so then any such row refuses (fail
     * closed). Same derivation as
     * withheldByPoll(); rows of other chats never count.
     */
    private function unlinkedWithheldRowNotOlder(string $chatId, string $messageId): bool
    {
        $query = OperatorInbox::query()
            ->where('conversation_id', $chatId)
            ->whereNull('activity_id');

        $sentAt = self::epochSecondsFromMessageId($messageId);
        if ($sentAt !== null) {
            $query->where('ts', '>=', Carbon::createFromTimestampUTC($sentAt - self::UNLINKED_MARGIN_SECONDS));
        }

        foreach ($query->select(['id', 'text', 'text_withheld'])->lazyById(200, 'id') as $row) {
            if ($this->textSanitizer->inboxRowPromptMeta($row)['withheld']) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whole seconds of a Teams chat message id read as epoch ms; null when
     * that is more than a day in the future (an over-long id saturates the
     * int cast at PHP_INT_MAX, which lands here too).
     */
    private static function epochSecondsFromMessageId(string $messageId): ?int
    {
        $seconds = intdiv((int) $messageId, 1000);

        return $seconds <= now()->getTimestamp() + 86400 ? $seconds : null;
    }

    /**
     * Refusal text when an inbox row recorded the requested attachment_id for
     * this message and the Graph message no longer lines up with that row;
     * null when no row listed it (poll_operator_messages never handed out
     * that ordinal, including a row that recorded no refs) or they agree.
     *
     * @param  array<int, array<string, mixed>>  $graphRefs
     */
    private function pollRefsMismatch(string $chatId, string $messageId, string $attachmentId, array $message, array $graphRefs): ?string
    {
        $rows = OperatorInbox::query()
            ->where('conversation_id', $chatId)
            ->where('activity_id', $messageId)
            ->whereNotNull('attachments')
            ->get(['attachments'])
            ->filter(static fn (OperatorInbox $row): bool => is_array($row->attachments) && in_array(
                $attachmentId,
                array_column(array_filter($row->attachments, 'is_array'), 'attachment_id'),
                true,
            ));

        if ($rows->isEmpty()) {
            return null;
        }

        if (! empty($message['lastEditedDateTime'])) {
            return 'Teams reports this message as edited, so its attachment numbering may differ from what poll_operator_messages listed; no image was returned. Ask the operator to paste the image again.';
        }

        $live = self::kindCounts($graphRefs);
        foreach ($rows as $row) {
            $recorded = self::kindCounts(is_array($row->attachments) ? $row->attachments : []);
            if ($recorded !== $live) {
                return "Teams has {$live['inline']} inline image(s) and {$live['file']} file(s) on this message but poll_operator_messages recorded {$recorded['inline']} and {$recorded['file']}, so the requested attachment cannot be matched to the one listed; no image was returned. Ask the operator to paste the image again.";
            }
        }

        return null;
    }

    /**
     * @param  array<int, mixed>  $refs
     * @return array{inline: int, file: int}
     */
    private static function kindCounts(array $refs): array
    {
        $counts = ['inline' => 0, 'file' => 0];
        foreach ($refs as $ref) {
            $kind = is_array($ref) ? ($ref['kind'] ?? null) : null;
            if ($kind === 'inline' || $kind === 'file') {
                $counts[$kind]++;
            }
        }

        return $counts;
    }

    /** @return array{error: string} */
    private function graphFailure(\Throwable $e, string $chatId, string $what): array
    {
        $status = $e instanceof GraphClientException ? $e->getHttpStatus() : 0;
        // #5398: a 401 whose token refresh failed is a token failure, not the permission
        // refusal the 401/403 arm below names, so it gets its own record key and message.
        $tokenRefreshFailed = $e instanceof GraphTokenRefreshFailedException;

        Log::warning('[ChetDataSurface] Teams message attachment read failed', [
            'chat_id' => $chatId,
            'stage' => $what,
            'status' => $status,
        ] + ($tokenRefreshFailed ? ['token_refresh' => 'failed'] : []));

        if ($tokenRefreshFailed) {
            return ['error' => "Teams {$what} read failed: Microsoft Graph answered HTTP {$status} and the PSA's Graph token refresh then failed, so no fresh token was obtained; nothing was read."];
        }

        return match ($status) {
            401, 403 => ['error' => "Teams refused the {$what} read (HTTP {$status}): the PSA's Microsoft Graph app registration needs the ".TeamsChatReadToolset::HOSTED_CONTENT_PERMISSION.' application permission with admin consent to read chat images. Ask the operator to grant it; nothing was read.'],
            404 => ['error' => 'Attachment not found on this message'],
            default => ['error' => "Teams {$what} read failed (HTTP {$status}); nothing was read."],
        };
    }
}
