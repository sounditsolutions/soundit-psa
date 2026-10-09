<?php

namespace App\Services\Mcp;

use App\Services\Graph\GraphClientException;

/**
 * #6121: the event read an update with a body makes immediately before its PATCH
 * (StaffCalendarToolExecutor::updateBodyPreservingTeamsJoin) failed upstream. Only that GET was
 * sent; the write never was, so nothing can have reached the calendar. Both write paths treat
 * it as a determinate "not sent" failure, never as the indeterminate outcome of a sent write.
 *
 * #6257 (C-56): the message is built from the wrapped exception's class and HTTP status only,
 * never its message, so no mailbox, event id, path or vendor text is copied into this
 * exception whatever a GraphClientException subclass's message carries. The
 * GraphClientException is kept as getPrevious().
 *
 * A GraphTokenException from the read is not wrapped: it keeps its own "not sent" arm.
 */
class CalendarPreReadFailedException extends \RuntimeException
{
    public function __construct(GraphClientException $previous)
    {
        $status = $previous->getHttpStatus();
        parent::__construct('The event read before the update failed: '.class_basename($previous).($status > 0 ? " (HTTP status {$status})" : ' (no HTTP status)'), 0, $previous);
    }
}
