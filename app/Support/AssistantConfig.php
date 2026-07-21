<?php

namespace App\Support;

use App\Models\Setting;

/**
 * psa-e317t / psa-uw2o.13: OPERATOR INTENT and ELIGIBILITY are different
 * questions, and conflating them produced three separate defects.
 *
 * isEnabled() answers "is the Assistant running right now", which is
 * `intent AND eligible`. That single boolean was then reused to answer two
 * questions it cannot answer:
 *
 *  - "does the operator want this on?" — the settings checkbox rendered from the
 *    composite, so on a non-Anthropic install it showed UNCHECKED even when the
 *    operator had switched it on. Saving the card then wrote that back as '0'
 *    and silently destroyed the stored intent (F4).
 *  - "why is it not running?" — the three disabled notices could not tell an
 *    install that never configured AI (say nothing) from one that cannot run the
 *    Assistant on its provider (say so) from one that simply switched it off
 *    (say how to switch it back) (F2/F3).
 *
 * So the three states are named here, once, and every surface reads them from
 * this class instead of re-deriving them. The notice COPY lives here too: F2 was
 * caused by three views each restating the predicate in their own words, and two
 * of them drifting.
 */
class AssistantConfig
{
    /** No AI provider configured at all — this install never wanted an Assistant. */
    public const REASON_NO_PROVIDER = 'no_provider';

    /** AI is configured, but not on the Anthropic provider the tool loop requires. */
    public const REASON_WRONG_PROVIDER = 'wrong_provider';

    /** Eligible in every way; the operator switched it off. */
    public const REASON_SWITCHED_OFF = 'switched_off';

    /**
     * psa-uw2o.1: this predicate gates a safety control, so its failure mode is
     * load-bearing. It fails CLOSED — see the note on operatorIntent(), which is
     * where that property actually comes from.
     *
     * Do NOT "harden" this by wrapping it in a try/catch that returns a default:
     * that would silently invert it to fail-open, and a gate that fails open on
     * error is the same class of bug this method was written to close.
     *
     * The two halves below are exactly the two clauses this method has always
     * had, in the same order and with the same short-circuit: eligibility first
     * (so an unconfigured install never reaches the Setting read), intent
     * second. Splitting them named the halves; it did not change what this
     * returns, and AssistantEnabledGateTest pins that in both directions.
     */
    public static function isEnabled(): bool
    {
        return self::isEligible() && self::operatorIntent();
    }

    /**
     * Can the Assistant run here at all? Infrastructure, not preference.
     *
     * The agentic tool loop is Anthropic-only, so another provider — or no key —
     * means the Assistant cannot function regardless of what the operator wants.
     * This is NOT a record of the operator's choice and must never be rendered
     * as one.
     */
    public static function isEligible(): bool
    {
        return AiConfig::isConfigured() && AiConfig::provider() === 'anthropic';
    }

    /**
     * Does the operator want the Assistant on? This is what the settings
     * checkbox renders from, and the only thing that checkbox writes.
     *
     * psa-98dq, ruled by Charlie 2026-07-21: the Assistant DEFAULTS OFF. It
     * previously returned enabled when the setting was ABSENT, so merely pasting
     * an Anthropic key silently activated a write-capable staff assistant — the
     * only AI cluster in this codebase that defaulted on, and the only one that
     * granted writes by doing so. Every sibling (triage, technician, agent,
     * portal chatbot, personas) ships dormant; this now matches. A present key
     * confers no capability on its own.
     *
     * The Setting read is deliberately UNGUARDED: a storage error propagates
     * rather than being swallowed into a default, which is what makes isEnabled()
     * fail CLOSED. (The AiConfig calls in isEligible() do swallow Throwable and
     * fall back to .env, so the fail-closed property comes from this line
     * specifically.) Do not wrap it.
     */
    public static function operatorIntent(): bool
    {
        $value = Setting::getValue('assistant_enabled');

        return $value !== null && (bool) $value;
    }

    /**
     * Why the Assistant is not running, or null when it is.
     *
     * The three states are genuinely different and must not be reported to an
     * operator as if they were the same thing.
     */
    public static function disabledReason(): ?string
    {
        if (self::isEnabled()) {
            return null;
        }

        if (! AiConfig::isConfigured()) {
            return self::REASON_NO_PROVIDER;
        }

        if (AiConfig::provider() !== 'anthropic') {
            return self::REASON_WRONG_PROVIDER;
        }

        return self::REASON_SWITCHED_OFF;
    }

    /**
     * Should a disabled-Assistant notice appear in the UI?
     *
     * THE one predicate for all three notice sites (topbar, ticket action row,
     * ticket timeline). psa-uw2o.13 F2: it used to be restated in each of those
     * views, and two of them had already drifted to a bare "not enabled" — so an
     * install with no AI provider at all, which never wanted an Assistant, was
     * told on every ticket page that its Assistant was disabled.
     *
     * A deployment that never configured AI is not missing anything and gets
     * SILENCE. Nagging every page of every non-AI deployment is noise, and noise
     * trains people to ignore notices — including this one.
     */
    public static function shouldShowDisabledNotice(): bool
    {
        $reason = self::disabledReason();

        return $reason !== null && $reason !== self::REASON_NO_PROVIDER;
    }

    /**
     * The short statement of what is wrong, for the notice sites. Null when no
     * notice is warranted.
     */
    public static function disabledSummary(): ?string
    {
        return match (self::disabledReason()) {
            self::REASON_SWITCHED_OFF => 'AI Assistant is disabled',
            self::REASON_WRONG_PROVIDER => 'AI Assistant is unavailable',
            default => null,
        };
    }

    /**
     * How to get the Assistant back. Null when no notice is warranted.
     *
     * psa-uw2o.13 F3: this used to exist only inside `title` attributes — one on
     * an inert span and one on a DISABLED button, which is not keyboard
     * focusable at all — and the timeline notice offered no recovery path in any
     * form. Tooltips are not dependable for keyboard or touch users, so every
     * site now renders this as real text.
     *
     * The two causes get DIFFERENT advice on purpose: "turn it on in Settings"
     * is actively misleading on an install where the switch is already on and
     * the provider is what blocks it.
     */
    public static function disabledRecovery(): ?string
    {
        return match (self::disabledReason()) {
            self::REASON_SWITCHED_OFF => 'Turn it on in Settings › Integrations › AI Assistant.',
            self::REASON_WRONG_PROVIDER => 'It needs the Anthropic AI provider. Change the provider in Settings › Integrations › AI Provider.',
            default => null,
        };
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
