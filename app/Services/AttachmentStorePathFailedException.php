<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;

/**
 * #5805: the update that points a stored attachment's row at its file threw (in practice a
 * QueryException, whose message carries the SQL bindings: the path, and so the sanitized client
 * filename). writeOrRollBack throws this in its place. The message carries the attachment id
 * only; getPrevious() is the original throw, for code that needs it. A deadlock or lost
 * connection is not wrapped in this: it is rethrown as a QueryException with the bindings
 * withheld (#6029, AttachmentService::writeOrRollBack).
 *
 * C-56: the framework's own report would put this exception in the log context, and the line
 * formatter writes each previous exception's message after it, so report() writes the record
 * itself: the attachment id and the previous exception's class only.
 *
 * #6028: PHP's own string form appends every previous exception's message ("Next ..."), and the
 * database-uuids failer stores (string) $e in failed_jobs.exception, so __toString() is cut at
 * this exception: its class, the id-only message and the frames' file:line and function, with no
 * arguments and nothing from getPrevious() but its class. getPrevious() itself still returns the
 * original, so a sink that walks the chain itself (Monolog's normalizer given ['exception' => $e])
 * still reaches its message. #6099: the handler's own path stays clear only because report()
 * returns nothing, so Handler::reportThrowable stops here (AttachmentStoreRollbackTest pins
 * that record); no guard stops a later Log call that passes this exception as context. The cut holds while
 * this is the outermost exception: one that wraps it prints the chain by PHP's own string form,
 * which reads each previous message directly and never calls this method.
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

    public function __toString(): string
    {
        $lines = [self::class.': '.$this->getMessage().' in '.$this->getFile().':'.$this->getLine(), 'Stack trace:'];
        foreach ($this->getTrace() as $i => $frame) {
            $where = isset($frame['file']) ? $frame['file'].'('.($frame['line'] ?? 0).')' : '[internal function]';
            $lines[] = "#{$i} {$where}: ".($frame['class'] ?? '').($frame['type'] ?? '').($frame['function'] ?? '').'()';
        }
        $lines[] = '#'.count($this->getTrace()).' {main}';
        if ($this->getPrevious() !== null) {
            $lines[] = 'Previous: '.$this->getPrevious()::class.' (message withheld, C-56)';
        }

        return implode("\n", $lines);
    }
}
