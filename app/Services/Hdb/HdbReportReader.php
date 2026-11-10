<?php

namespace App\Services\Hdb;

/** API-only report reader; authorization precedes every vendor request. */
final class HdbReportReader
{
    public function fetchForNote(?int $ticketId, ?int $noteId): HdbReportResult
    {
        $authorization = (new HdbReportFetchAuthorizer)->authorizeNote($ticketId, $noteId);

        if ($authorization->refused()) {
            return self::refusal($authorization->refusal ?? HdbReportFetchRefusal::NoKeyedNote);
        }

        return (new HdbApiReportClient)->report((string) $authorization->pressId, (int) $authorization->ticketId);
    }

    public static function refusal(HdbReportFetchRefusal $refusal): HdbReportResult
    {
        return HdbReportResult::refused($refusal)->fromApi();
    }
}
