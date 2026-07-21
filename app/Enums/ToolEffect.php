<?php

namespace App\Enums;

/**
 * What a dispatchable assistant tool DOES to state — the classification a
 * read-only surface filters on.
 *
 * This exists so that "which tools write?" cannot be answered by a list kept
 * alongside the dispatch table, only by the dispatch table itself. See
 * AssistantToolExecutor::dispatchTable(): every tool is registered as a
 * [ToolEffect, handler] pair, so a tool that can be dispatched has necessarily
 * been classified. psa-uw2o.13/.14 — the defect this shape closes was a mutating
 * dispatch arm that no write-list named, executed by a bot called ReadOnly.
 */
enum ToolEffect: string
{
    /** Returns data. Safe for a read-only surface to expose and execute. */
    case Read = 'read';

    /** Changes state (PSA records, wiki, held proposals). Never read-only. */
    case Write = 'write';
}
