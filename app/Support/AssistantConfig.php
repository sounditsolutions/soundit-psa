<?php

namespace App\Support;

use App\Models\Setting;

class AssistantConfig
{
    /**
     * psa-uw2o.1: this predicate now gates a safety control, so its failure mode
     * is load-bearing. It fails CLOSED — but only because the Setting::getValue
     * below is unguarded and will propagate a storage error rather than swallow
     * it. (The AiConfig calls above it do swallow Throwable and fall back to
     * .env, so the fail-closed property comes from this line specifically.)
     *
     * Do NOT "harden" this by wrapping it in a try/catch that returns a default:
     * that would silently invert it to fail-open, and a gate that fails open on
     * error is the same class of bug this method was written to close.
     */
    public static function isEnabled(): bool
    {
        if (! AiConfig::isConfigured() || AiConfig::provider() !== 'anthropic') {
            return false;
        }

        $value = Setting::getValue('assistant_enabled');

        // psa-98dq, ruled by Charlie 2026-07-21: the Assistant DEFAULTS OFF.
        // It previously returned enabled when the setting was ABSENT, so merely
        // pasting an Anthropic key silently activated a write-capable staff
        // assistant — the only AI cluster in this codebase that defaulted on,
        // and the only one that granted writes by doing so. Every sibling
        // (triage, technician, agent, portal chatbot, personas) ships dormant;
        // this now matches. A present key confers no capability on its own.
        return $value !== null && (bool) $value;
    }

    public static function dailyTokenLimit(): int
    {
        return (int) (Setting::getValue('assistant_daily_token_limit') ?: 500_000);
    }

    public static function maxMessagesPerConversation(): int
    {
        return (int) (Setting::getValue('assistant_max_messages') ?: 50);
    }
}
