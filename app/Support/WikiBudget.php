<?php

namespace App\Support;

use App\Models\WikiRun;

/**
 * Single shared accounting for ALL wiki AI spend (spec §5.3). Mining and overview
 * composition draw from one daily pool, so the budget is summed across every
 * WikiRunType — never per run_type. Both MineTicketKnowledge and WikiOverviewComposer
 * gate on dailyLimitReached() so neither can starve the other.
 */
class WikiBudget
{
    /** Total wiki AI tokens spent today across ALL run types (one shared pool). */
    public static function tokensUsedToday(): int
    {
        return (int) WikiRun::whereDate('created_at', today())
            ->whereNotNull('ai_tokens_used')->get()
            ->sum(fn (WikiRun $r) => ((int) ($r->ai_tokens_used['input'] ?? 0)) + ((int) ($r->ai_tokens_used['output'] ?? 0)));
    }

    public static function dailyLimitReached(): bool
    {
        return self::tokensUsedToday() >= WikiConfig::dailyTokenLimit();
    }
}
