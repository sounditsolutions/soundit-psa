<?php

namespace App\Support;

use App\Models\Setting;
use App\Models\User;

class TriageConfig
{
    /**
     * Whether the triage pipeline is enabled at all.
     */
    public static function isEnabled(): bool
    {
        return (bool) Setting::getValue('triage_enabled');
    }

    /**
     * Whether to auto-dispatch triage on new ticket creation.
     */
    public static function autoTriageEnabled(): bool
    {
        return static::isEnabled() && (bool) Setting::getValue('triage_auto_new_tickets');
    }

    /**
     * Whether the scheduled review cron is enabled.
     */
    public static function autoReviewEnabled(): bool
    {
        return static::isEnabled() && (bool) Setting::getValue('triage_auto_review');
    }

    /**
     * Whether a specific pipeline stage is enabled.
     */
    public static function stageEnabled(string $stage): bool
    {
        // Default to true if not explicitly set
        $value = Setting::getValue("triage_stage_{$stage}");

        return $value === null || (bool) $value;
    }

    /**
     * The default technician to assign tickets to when no client primary tech exists.
     */
    public static function defaultAssigneeId(): ?int
    {
        $value = Setting::getValue('triage_default_assignee_id');

        return $value ? (int) $value : null;
    }

    /**
     * Get the user ID for audit trail attribution on triage-created actions.
     * Follows T2TConfig::systemUserId() pattern exactly.
     */
    public static function systemUserId(): ?int
    {
        $configured = Setting::getValue('triage_system_user_id');

        if ($configured) {
            return (int) $configured;
        }

        return User::orderBy('id')->value('id');
    }

    /**
     * AI model override for triage (falls back to AiConfig default).
     */
    public static function model(): string
    {
        $override = Setting::getValue('triage_model');

        return $override ?: AiConfig::model();
    }

    /**
     * Max tokens per triage run (input + output across all AI calls).
     */
    public static function maxTokensPerRun(): int
    {
        return (int) (Setting::getValue('triage_max_tokens_per_run') ?: 200_000);
    }

    /**
     * Daily token ceiling across all triage runs.
     */
    public static function dailyTokenLimit(): int
    {
        return (int) (Setting::getValue('triage_daily_token_limit') ?: 2_000_000);
    }

    /**
     * How often (in minutes) the review cron should run. Default: 60.
     *
     * Floored at 1 (psa-lqlu, defense-in-depth): a zero/negative value would make the
     * throttle window non-positive and the staleness-alarm TTL non-future — flooding the
     * pass + the operator alert. The Settings UI already validates min:5; this guards a
     * future unvalidated write path.
     */
    public static function reviewFrequencyMinutes(): int
    {
        return max(1, (int) (Setting::getValue('triage_review_frequency_minutes') ?: 60));
    }

    /**
     * Max tickets to process per review cron run.
     */
    public static function reviewBatchSize(): int
    {
        return (int) (Setting::getValue('triage_review_batch_size') ?: 20);
    }

    /**
     * Whether the reviewer is allowed to auto-close resolved/junk tickets.
     */
    public static function reviewAutoCloseEnabled(): bool
    {
        return (bool) Setting::getValue('triage_review_auto_close');
    }

    /**
     * Minimum confidence threshold (0-100) for the reviewer to auto-close.
     * Only "high" confidence assessments are eligible. This further gates
     * auto-close to prevent borderline closures.
     * Default: 80 (meaning high-confidence assessments auto-close by default when enabled).
     */
    public static function reviewAutoCloseThreshold(): int
    {
        $value = Setting::getValue('triage_review_auto_close_threshold');

        return $value !== null ? (int) $value : 80;
    }

    /**
     * Whether AI-suggested ticket categories require staff approval before being
     * applied (psa-xop / GitHub #80). When enabled (the default), the triage
     * `set_ticket_category` tool records a pending suggestion for the approval
     * queue instead of writing the category straight onto the ticket. Disable to
     * restore immediate AI category writes.
     */
    public static function categoryApprovalEnabled(): bool
    {
        $value = Setting::getValue('triage_category_approval');

        return $value === null || (bool) $value;
    }
}
