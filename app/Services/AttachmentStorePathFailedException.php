<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;

/**
 * #5805: the update that points a stored attachment's row at its file threw (in practice a
 * QueryException, whose message carries the SQL bindings: the path, and so the sanitized client
 * filename). writeOrRollBack throws this in its place. The message carries the attachment id
 * only; getPrevious() is the original throw, for code that needs it.
 *
 * C-56: the framework's own report would put this exception in the log context, and the line
 * formatter writes each previous exception's message after it, so report() writes the record
 * itself: the attachment id and the previous exception's class only.
 */
class AttachmentStorePathFailedException extends \RuntimeException
{
    public function __construct(public readonly int $attachmentId, \Throwable $previous)
    {
        parent::__construct("Attachment {$attachmentId}: the storage path update threw", 0, $previous);
    }

    public function report(): void
    {
        Log::error($this->getMessage(), [
            'attachment_id' => $this->attachmentId,
            'exception' => self::class,
            'previous' => $this->getPrevious() === null ? null : $this->getPrevious()::class,
        ]);
    }
}
