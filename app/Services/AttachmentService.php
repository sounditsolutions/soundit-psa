<?php

namespace App\Services;

use App\Models\Attachment;
use App\Models\Email;
use App\Services\Graph\GraphClient;
use App\Services\Graph\GraphClientException;
use App\Services\Graph\GraphTokenRefreshFailedException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class AttachmentService
{
    /**
     * Byte ceiling for an attached Outlook item (forward-as-attachment) fetched through Graph
     * $value. Checked against Graph's declared size before the fetch and against the bytes
     * actually returned after it. File attachments arrive inline as contentBytes in the
     * message read and carry no ceiling here.
     */
    public const MAX_ITEM_ATTACHMENT_BYTES = 25 * 1024 * 1024;

    private const FILE_ATTACHMENT = '#microsoft.graph.fileAttachment';

    private const ITEM_ATTACHMENT = '#microsoft.graph.itemAttachment';

    private const REFERENCE_ATTACHMENT = '#microsoft.graph.referenceAttachment';

    /**
     * Store an uploaded file and create an Attachment record.
     *
     * Creates the record first to get the ID for the storage path:
     *   attachments/{id}/{sanitized_filename}
     */
    public function storeUpload(UploadedFile $file, ?int $uploadedBy = null): Attachment
    {
        $sanitized = $this->sanitizeFilename($file->getClientOriginalName());

        $attachment = Attachment::create([
            'filename' => $sanitized,
            'original_filename' => $file->getClientOriginalName(),
            'mime_type' => $file->getMimeType() ?? 'application/octet-stream',
            'size_bytes' => $file->getSize(),
            'storage_path' => 'attachments/tmp', // placeholder, updated below
            'uploaded_by' => $uploadedBy,
        ]);

        $path = "attachments/{$attachment->id}/{$sanitized}";
        // #5716: the same rollback as storeFromContent (#5553, #5709).
        $this->writeOrRollBack($attachment, $path, fn () => Storage::disk('local')->putFileAs(
            "attachments/{$attachment->id}",
            $file,
            $sanitized,
        ));

        // #5719: ids, size and type only; the client's filename is not logged.
        Log::info('[Attachment] Stored upload', [
            'attachment_id' => $attachment->id,
            'size' => $file->getSize(),
            'mime' => $attachment->mime_type,
        ]);

        return $attachment;
    }

    /**
     * Store raw content (e.g. from Graph API) and create an Attachment record.
     */
    public function storeFromContent(
        string $content,
        string $originalFilename,
        string $mimeType,
        ?bool $isInline = false,
        ?string $contentId = null,
    ): Attachment {
        $sanitized = $this->sanitizeFilename($originalFilename);

        $attachment = Attachment::create([
            'filename' => $sanitized,
            'original_filename' => $originalFilename,
            'mime_type' => $mimeType,
            'size_bytes' => strlen($content),
            'storage_path' => 'attachments/tmp', // placeholder, updated below
            'is_inline' => $isInline ?? false,
            'content_id' => $contentId,
        ]);

        $path = "attachments/{$attachment->id}/{$sanitized}";
        $this->writeOrRollBack($attachment, $path, fn () => Storage::disk('local')->put($path, $content));

        // #5719: ids, size and type only. The filename and the Graph contentId come from the
        // client's email and are not logged (C-56, the batch's no-filename rule).
        Log::info('[Attachment] Stored from content', [
            'attachment_id' => $attachment->id,
            'size' => strlen($content),
            'mime' => $mimeType,
            'is_inline' => $isInline,
        ]);

        return $attachment;
    }

    /**
     * Writes the file through $write, then points the row at $path. #5553/#5709/#5716: when the
     * write throws or returns false (the local disk is 'throw' => false, so a failed write
     * returns false), or the storage_path update throws, no row is left naming the placeholder
     * or a missing file: the file and row are removed, best effort, and the failure is thrown
     * (a false write as AttachmentStoreFailedException). A cleanup step that throws is recorded
     * with the attachment id, the step and the exception class. #5806: so is a file delete that
     * returns false (the local disk does not throw), with exception null; the file may then be
     * left on disk with no row naming it. #5805: a throw from the storage_path update is thrown
     * as AttachmentStorePathFailedException (id only, the original as getPrevious()), since the
     * original's message can carry the path and so the filename.
     */
    private function writeOrRollBack(Attachment $attachment, string $path, \Closure $write): void
    {
        try {
            if ($write() === false) {
                throw new AttachmentStoreFailedException("Attachment {$attachment->id}: the file write returned false");
            }
            try {
                $attachment->update(['storage_path' => $path]);
            } catch (\Throwable $updateFailure) {
                throw new AttachmentStorePathFailedException($attachment->id, $updateFailure);
            }
        } catch (\Throwable $e) {
            foreach ([
                'file' => fn () => Storage::disk('local')->delete($path),
                'row' => fn () => Attachment::withTrashed()->whereKey($attachment->id)->forceDelete(),
            ] as $step => $cleanup) {
                try {
                    if ($cleanup() === false && $step === 'file') {
                        Log::warning('[Attachment] Cleanup after a failed store returned false', [
                            'attachment_id' => $attachment->id,
                            'step' => $step,
                            'exception' => null,
                        ]);
                    }
                } catch (\Throwable $cleanupFailure) {
                    Log::warning('[Attachment] Cleanup after a failed store threw', [
                        'attachment_id' => $attachment->id,
                        'step' => $step,
                        'exception' => $cleanupFailure::class,
                    ]);
                }
            }

            throw $e;
        }
    }

    /**
     * Link an attachment to a parent model (ticket, ticket_note, etc.).
     */
    public function linkTo(Attachment $attachment, string $attachableType, int $attachableId): void
    {
        $attachment->update([
            'attachable_type' => $attachableType,
            'attachable_id' => $attachableId,
        ]);
    }

    /**
     * Replace cid: references in HTML with local attachment URLs.
     *
     * For each inline attachment with a content_id, replaces
     * "cid:{contentId}" with the attachment's serving URL.
     *
     * @param  array<Attachment>  $attachments
     */
    public function replaceCidReferences(string $html, array $attachments): string
    {
        foreach ($attachments as $attachment) {
            if (! $attachment->is_inline || ! $attachment->content_id) {
                continue;
            }

            // Content IDs may be stored with or without angle brackets;
            // strip them for matching against the cid: URI scheme.
            $cid = trim($attachment->content_id, '<>');

            $html = str_replace(
                "cid:{$cid}",
                $attachment->url,
                $html,
            );
        }

        return $html;
    }

    /**
     * Read file contents from disk.
     */
    public function getContent(Attachment $attachment): ?string
    {
        $disk = Storage::disk('local');

        if (! $disk->exists($attachment->storage_path)) {
            Log::warning('[Attachment] File not found on disk', [
                'attachment_id' => $attachment->id,
                'storage_path' => $attachment->storage_path,
            ]);

            return null;
        }

        return $disk->get($attachment->storage_path);
    }

    /**
     * Scan a body for attachment URLs and link matching unlinked attachments to the given model.
     * Used after note creation to re-link ticket-level uploads to the specific note.
     */
    public function linkAttachmentsFromBody(string $body, string $attachableType, int $attachableId, int $ticketId): void
    {
        // Find all attachment URLs in the body: /attachments/{id}/{filename}
        preg_match_all('#/attachments/(\d+)/#', $body, $matches);

        if (empty($matches[1])) {
            return;
        }

        $ids = array_unique($matches[1]);

        // Only re-link attachments that are currently linked to this ticket (not to other notes)
        Attachment::whereIn('id', $ids)
            ->where('attachable_type', 'App\\Models\\Ticket')
            ->where('attachable_id', $ticketId)
            ->update([
                'attachable_type' => $attachableType,
                'attachable_id' => $attachableId,
            ]);
    }

    /**
     * Download all attachments from a Graph email and store them locally.
     * Returns array of created Attachment models, empty when the message has none or none was
     * stored. Returns null when the message read itself failed (#5394), so a caller can tell a
     * failed read from an empty list and try the read again; a failed or refused ITEM is not a
     * failed read and still yields an array. So is an attachment whose disk write is refused
     * (AttachmentStoreFailedException, #5709): it is skipped with a store_failed warning, and the
     * rest of the message is still stored (storeOrSkip).
     *
     * @return Attachment[]|null
     */
    public function downloadEmailAttachments(Email $email, GraphClient $graph, string $mailbox): ?array
    {
        if (! $email->graph_id) {
            return [];
        }

        try {
            $graphAttachments = $graph->getMessageAttachments($mailbox, $email->graph_id);
        } catch (\Throwable $e) {
            // C-56: the email id, the HTTP status and the exception class only, never the message
            // or (#5804) the Graph message id. A
            // GraphClientException's message no longer names the endpoint (#5679), but any other
            // Throwable's message is not known to be free of the mailbox or vendor text. status is
            // the GraphClientException's status when it is above 0: Graph's HTTP status, or the
            // status of a response GraphClient could not decode (e.g. 200 with invalid JSON). It is
            // null otherwise (connect/timeout, a token request that failed at entry, a non-Graph
            // throw). #5813: a 401 whose token refresh then failed records status 401 and
            // token_refresh => failed, as the item read does (#5398).
            $httpStatus = $e instanceof GraphClientException ? $e->getHttpStatus() : 0;
            Log::warning('[AttachmentService] Failed to fetch email attachments', [
                'email_id' => $email->id,
                'status' => $httpStatus > 0 ? $httpStatus : null,
                'exception' => $e::class,
            ] + ($e instanceof GraphTokenRefreshFailedException ? ['token_refresh' => 'failed'] : []));

            return null;
        }

        $attachments = [];

        foreach ($graphAttachments as $ga) {
            $type = $ga['@odata.type'] ?? '';

            if ($type === self::ITEM_ATTACHMENT) {
                $item = $this->storeOrSkip($email, $ga, fn () => $this->downloadItemAttachment($email, $graph, $mailbox, $ga));
                if ($item !== null) {
                    $attachments[] = $item;
                }

                continue;
            }

            if ($type === self::REFERENCE_ATTACHMENT) {
                $placeholder = $this->storeOrSkip($email, $ga, fn () => $this->storeReferencePlaceholder($email, $ga));
                if ($placeholder !== null) {
                    $attachments[] = $placeholder;
                }

                continue;
            }

            if ($type !== self::FILE_ATTACHMENT) {
                $this->warnSkipped($email, $ga, 'unsupported_type');

                continue;
            }

            $contentBytes = $ga['contentBytes'] ?? null;
            if (! $contentBytes) {
                $this->warnSkipped($email, $ga, 'empty_content');

                continue;
            }

            $content = base64_decode($contentBytes);
            if ($content === false) {
                $this->warnSkipped($email, $ga, 'undecodable_content');

                continue;
            }

            $attachment = $this->storeOrSkip($email, $ga, fn () => $this->storeFromContent(
                $content,
                $ga['name'] ?? 'attachment',
                $ga['contentType'] ?? 'application/octet-stream',
                isInline: $ga['isInline'] ?? false,
                contentId: $ga['contentId'] ?? null,
            ));

            if ($attachment !== null) {
                $attachments[] = $attachment;
            }
        }

        return $attachments;
    }

    /**
     * One attachment's store for email intake. A refused disk write (AttachmentStoreFailedException;
     * the store has already rolled back its own row and file, best effort, #5709) skips that
     * attachment with a store_failed warning, as a refused item fetch does, so it never fails the
     * email's import. Any other throw is not caught here.
     *
     * @param  array<string, mixed>  $ga
     * @param  \Closure(): ?Attachment  $store
     */
    private function storeOrSkip(Email $email, array $ga, \Closure $store): ?Attachment
    {
        try {
            return $store();
        } catch (AttachmentStoreFailedException) {
            $this->warnSkipped($email, $ga, 'store_failed');

            return null;
        }
    }

    /**
     * An attached Outlook item ("Forward as attachment"). The message read carries no
     * contentBytes for it, so its raw contents come from Graph $value: MIME for a message,
     * vCard for a contact, iCal for an event (attachment-get, "Get the raw contents of a file
     * or item attachment"). A failed or refused fetch logs a warning and stores nothing; it
     * never fails the email.
     *
     * @param  array<string, mixed>  $ga
     */
    private function downloadItemAttachment(Email $email, GraphClient $graph, string $mailbox, array $ga): ?Attachment
    {
        $attachmentId = $ga['id'] ?? null;
        if (! is_string($attachmentId) || $attachmentId === '') {
            $this->warnSkipped($email, $ga, 'missing_attachment_id');

            return null;
        }

        $declaredSize = $ga['size'] ?? null;
        if (is_int($declaredSize) && $declaredSize > self::MAX_ITEM_ATTACHMENT_BYTES) {
            $this->warnSkipped($email, $ga, 'over_size_ceiling', ['size_bytes' => $declaredSize]);

            return null;
        }

        try {
            $raw = $graph->getMessageAttachmentRaw($mailbox, $email->graph_id, $attachmentId);
        } catch (\Throwable $e) {
            // #5136: getCode() is not an HTTP status for every Throwable. A GraphClientException
            // carrying a status > 0 is reported as fetch_failed with that status. Anything else
            // (a GraphClientException with status 0, e.g. connect/timeout or a token failure at
            // entry, or any other Throwable, whose code is not read) is reported as
            // status_unknown: status null plus the exception class (#5399). Never the message (C-56).
            // A 401 whose token refresh then failed also carries token_refresh => failed, so the
            // record names the token failure as the cause (#5398).
            $httpStatus = $e instanceof GraphClientException ? $e->getHttpStatus() : 0;
            if ($httpStatus > 0) {
                $this->warnSkipped($email, $ga, 'fetch_failed', ['status' => $httpStatus]
                    + ($e instanceof GraphTokenRefreshFailedException ? ['token_refresh' => 'failed'] : []));
            } else {
                $this->warnSkipped($email, $ga, 'status_unknown', [
                    'status' => null,
                    'exception' => $e::class,
                ]);
            }

            return null;
        }

        if ($raw === '') {
            $this->warnSkipped($email, $ga, 'empty_content');

            return null;
        }

        if (strlen($raw) > self::MAX_ITEM_ATTACHMENT_BYTES) {
            $this->warnSkipped($email, $ga, 'over_size_ceiling', ['size_bytes' => strlen($raw)]);

            return null;
        }

        [$extension, $mimeType] = match (true) {
            str_starts_with(ltrim($raw), 'BEGIN:VCARD') => ['vcf', 'text/vcard'],
            str_starts_with(ltrim($raw), 'BEGIN:VCALENDAR') => ['ics', 'text/calendar'],
            default => ['eml', 'message/rfc822'],
        };

        return $this->storeFromContent(
            $raw,
            $this->itemBaseName($ga['name'] ?? null).'.'.$extension,
            $mimeType,
            isInline: false,
        );
    }

    /**
     * A referenceAttachment is a link to a cloud file. Graph v1.0 returns its name, size and
     * contentType but no link (referenceAttachment resource), and $value answers 405, so there
     * is nothing to download. Record a visible text placeholder naming it instead of dropping it.
     *
     * @param  array<string, mixed>  $ga
     */
    private function storeReferencePlaceholder(Email $email, array $ga): Attachment
    {
        $name = is_string($ga['name'] ?? null) && $ga['name'] !== '' ? $ga['name'] : 'unnamed';

        $this->warnSkipped($email, $ga, 'reference_not_downloaded');

        return $this->storeFromContent(
            "Linked cloud attachment (not downloaded): {$name}\n",
            $this->itemBaseName($name).'-linked-file.txt',
            'text/plain',
            isInline: false,
        );
    }

    /**
     * Filename stem for a stored item: the slugged display name, or "forwarded-message" when
     * the name is missing or slugs to nothing. Str::slug already drops '/' and '\', so no path
     * separator survives either way; turning them into spaces first only keeps a word
     * separator where they stood ("Q1/Q2" gives "q1-q2" rather than "q1q2").
     */
    private function itemBaseName(mixed $name): string
    {
        $slug = is_string($name)
            ? Str::limit(Str::slug(str_replace(['/', '\\'], ' ', $name)), 100, '')
            : '';

        return trim($slug, '-') !== '' ? trim($slug, '-') : 'forwarded-message';
    }

    /**
     * One warning per attachment that is not stored as its own bytes. Ids, type and a reason
     * token only; no name, subject or content.
     *
     * @param  array<string, mixed>  $ga
     * @param  array<string, mixed>  $extra
     */
    private function warnSkipped(Email $email, array $ga, string $reason, array $extra = []): void
    {
        Log::warning('[AttachmentService] Email attachment content not stored', [
            'email_id' => $email->id,
            'graph_id' => $email->graph_id,
            'attachment_id' => is_string($ga['id'] ?? null) ? $ga['id'] : null,
            'odata_type' => is_string($ga['@odata.type'] ?? null) ? $ga['@odata.type'] : null,
            'reason' => $reason,
        ] + $extra);
    }

    /**
     * Resize an image to max 1568px on longest side and return base64-encoded content.
     * Returns null if the file isn't a valid image or GD processing fails.
     */
    public function resizeImageForAi(Attachment $attachment): ?string
    {
        $content = $this->getContent($attachment);
        if (! $content) {
            return null;
        }

        return $this->resizeImageBytesForAi($content, (string) $attachment->mime_type);
    }

    /**
     * resizeImageForAi() over bytes already in hand (Teams hosted content,
     * card 2Cj3kOsy): same 1568px bound, same re-encode per $mimeType.
     */
    public function resizeImageBytesForAi(string $content, string $mimeType): ?string
    {
        if ($content === '') {
            return null;
        }

        $image = @imagecreatefromstring($content);
        if (! $image) {
            return null;
        }

        $maxDim = 1568;
        $width = imagesx($image);
        $height = imagesy($image);

        if ($width > $maxDim || $height > $maxDim) {
            if ($width >= $height) {
                $newWidth = $maxDim;
                $newHeight = (int) round($height * ($maxDim / $width));
            } else {
                $newHeight = $maxDim;
                $newWidth = (int) round($width * ($maxDim / $height));
            }

            $resized = imagecreatetruecolor($newWidth, $newHeight);

            if (in_array($mimeType, ['image/png', 'image/webp', 'image/gif'])) {
                imagealphablending($resized, false);
                imagesavealpha($resized, true);
            }

            imagecopyresampled($resized, $image, 0, 0, 0, 0, $newWidth, $newHeight, $width, $height);
            imagedestroy($image);
            $image = $resized;
        }

        ob_start();
        if ($mimeType === 'image/png') {
            imagepng($image);
        } elseif ($mimeType === 'image/webp') {
            imagewebp($image);
        } elseif ($mimeType === 'image/gif') {
            imagepng($image); // GIF → PNG for Anthropic compatibility
        } else {
            imagejpeg($image, null, 85);
        }
        $output = ob_get_clean();
        imagedestroy($image);

        return base64_encode($output);
    }

    /**
     * Sanitize a filename: slugify the name portion, preserve extension.
     *
     * Examples:
     *   "My Report (2).pdf" → "my-report-2.pdf"
     *   "screen shot 2024.png" → "screen-shot-2024.png"
     *   "../../etc/passwd" → "etc-passwd"
     */
    private function sanitizeFilename(string $filename): string
    {
        // Strip any directory components for safety
        $filename = basename($filename);

        $extension = pathinfo($filename, PATHINFO_EXTENSION);
        $name = pathinfo($filename, PATHINFO_FILENAME);

        // Slugify the name portion
        $slug = Str::slug($name);

        // If slugify produced an empty string, use a UUID
        if ($slug === '') {
            $slug = (string) Str::uuid();
        }

        // Re-attach extension if present
        if ($extension !== '') {
            return $slug.'.'.Str::lower($extension);
        }

        return $slug;
    }
}
