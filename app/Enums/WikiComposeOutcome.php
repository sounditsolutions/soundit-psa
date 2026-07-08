<?php

namespace App\Enums;

/**
 * Signal returned by WikiOverviewComposer::compose() indicating why the run stopped.
 * Callers that already ignore the void return remain unaffected.
 */
enum WikiComposeOutcome
{
    /** AI ran and composed a new overview body. */
    case Composed;

    /** Fact digest hash unchanged since last compose — skipped without any AI call. */
    case SkippedUnchanged;

    /** Daily token budget already reached — skipped without any AI call. */
    case SkippedBudget;

    /** No active overview page exists for this client. */
    case SkippedNoOverview;

    /** AI returned an empty body or the output-scan quarantined it. */
    case Quarantined;
}
