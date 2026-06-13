<?php

namespace App\Support;

use App\Models\Setting;

class WikiConfig
{
    // Spec §9: wiki_enabled defaults OFF. Further keys (wiki_auto_mine, budgets,
    // staleness windows) arrive with the phases that consume them — YAGNI here.
    public static function isEnabled(): bool
    {
        return (bool) Setting::getValue('wiki_enabled');
    }

    /** Spec §9: mining is explicit opt-in and requires the master switch. */
    public static function autoMineEnabled(): bool
    {
        return self::isEnabled() && (bool) Setting::getValue('wiki_auto_mine');
    }

    public static function model(): string
    {
        $override = Setting::getValue('wiki_model');

        return $override ?: AiConfig::model();
    }

    public static function maxTokensPerRun(): int
    {
        return (int) (Setting::getValue('wiki_max_tokens_per_run') ?: 50_000);
    }

    public static function dailyTokenLimit(): int
    {
        return (int) (Setting::getValue('wiki_daily_token_limit') ?: 500_000);
    }
}
