<?php

namespace App\Services\Mcp;

use App\Services\Graph\GraphClientException;

/**
 * #6121: the event read an update with a body makes immediately before its PATCH
 * (StaffCalendarToolExecutor::updateBodyPreservingTeamsJoin) failed upstream. Only that GET was
 * sent; the write never was, so nothing can have reached the calendar. Both write paths treat
 * it as a determinate "not sent" failure, never as the indeterminate outcome of a sent write.
 * The GraphClientException is kept as getPrevious() and its message is copied as it is. That
 * can be any GraphClientException subclass's text (#6194): GraphClient's method-and-status
 * text, its invalid-JSON text, a GraphShapeDriftException's text from
 * CalendarGraphShapes::assertEvent(), or a GraphTokenRefreshFailedException's after a 401.
 * The executor's audit row does not use this message (#6186).
 *
 * A GraphTokenException from the read is not wrapped: it keeps its own "not sent" arm.
 */
class CalendarPreReadFailedException extends \RuntimeException
{
    public function __construct(GraphClientException $previous)
    {
        parent::__construct($previous->getMessage(), 0, $previous);
    }
}
