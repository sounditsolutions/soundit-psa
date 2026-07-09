<?php

namespace App\Support;

use App\Models\Setting;

/**
 * Setting-backed reader for the daily technician-briefing feature, following the
 * {@see TechnicianConfig} / {@see T2TConfig} static-helper pattern.
 *
 * The subsystem ships DORMANT: {@see self::isEnabled()} defaults to false so a
 * fresh deploy never surprises staff with unsolicited daily email. An operator
 * turns it on in Settings once they're ready.
 */
class BriefingConfig
{
    /** Master on/off for the daily briefing. Default: OFF (opt-in). */
    public static function isEnabled(): bool
    {
        return (bool) Setting::getValue('briefing_enabled');
    }

    /** Operator-local time (HH:MM) at which the briefing fires. Default: 07:00. */
    public static function sendTimeLocal(): string
    {
        $value = Setting::getValue('briefing_time');

        return is_string($value) && preg_match('/^\d{2}:\d{2}$/', $value) ? $value : '07:00';
    }

    /**
     * How many hours back "overnight" reaches when collecting alerts that broke
     * while the technician was away. Default: 16h (covers an evening→morning gap).
     */
    public static function overnightHours(): int
    {
        $value = Setting::getValue('briefing_overnight_hours');

        return is_numeric($value) ? max(1, (int) $value) : 16;
    }

    /**
     * Cap on how many open tickets are listed in full before collapsing to a
     * "…and N more" line, keeping the email digestible. Default: 15.
     */
    public static function maxTicketsListed(): int
    {
        $value = Setting::getValue('briefing_max_tickets');

        return is_numeric($value) ? max(1, (int) $value) : 15;
    }

    /**
     * Whether to ask the AI for 1–2 suggested next actions. Default: true, but
     * always degrades gracefully to no suggestions when AI is not configured.
     */
    public static function includeAiSuggestions(): bool
    {
        $value = Setting::getValue('briefing_ai_suggestions');

        return $value === null || (bool) $value;
    }
}
