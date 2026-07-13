<?php

namespace App\Support;

use App\Models\Setting;

/**
 * Setting-backed config for the agent (Increment 1).
 * Ships dormant: enabled() defaults false, proposeCloseAutoThreshold() defaults null.
 * Mirrors TechnicianConfig idioms — except the auto-threshold reader which is
 * deliberately null-preserving (absent ≠ 0.0, it means "never auto-close").
 */
class AgentConfig
{
    /** Master on/off for the agent. Absent ⇒ false (dormant by default). */
    public static function enabled(): bool
    {
        return (bool) Setting::getValue('agent_enabled');
    }

    /**
     * Maximum number of propose-close proposals that may sit pending approval
     * at one time. Setting: agent_max_pending. Default: 10. Floor: 1.
     */
    public static function maxPendingProposals(): int
    {
        $value = Setting::getValue('agent_max_pending');

        return is_numeric($value) ? max(1, (int) $value) : 10;
    }

    /**
     * Confidence threshold above which the agent may auto-close without operator
     * approval. NULL = never auto-close (the safe default when absent/blank).
     *
     * SAFETY: do NOT mirror the max($floor, (float) getValue(...)) idiom here.
     * An absent setting yields getValue() = null → (float) null = 0.0 →
     * max(0.90, 0.0) = 0.90, silently enabling auto-close with nothing configured.
     * The null-preserving check below is intentional and load-bearing.
     *
     * Setting: propose_close_auto_threshold. Floor when set: 0.90.
     */
    public static function proposeCloseAutoThreshold(): ?float
    {
        $raw = Setting::getValue('propose_close_auto_threshold');
        if ($raw === null || trim((string) $raw) === '') {
            return null; // unset = never auto-close (the safe default)
        }

        return max(0.90, (float) $raw); // floor: can't auto-close below 0.90
    }

    /**
     * Minimum confidence below which a propose-close proposal is discarded
     * (too weak to surface to the operator). Setting: propose_close_approve_floor.
     * Default: 0.50.
     */
    public static function proposeCloseApproveFloor(): float
    {
        $value = Setting::getValue('propose_close_approve_floor');

        return is_numeric($value) ? (float) $value : 0.50;
    }

    /**
     * Quiet window (in days) the deterministic auto-close backstop requires with
     * NO inbound client note before a propose_close may AUTO-close (CO-19). A
     * client who wrote in within this window is, by definition, not done — so the
     * close is held for a human instead. Setting: agent_auto_quiet_days.
     * Default: 14. Floor: 1 (mirrors the maxPendingProposals floor idiom).
     */
    public static function autoQuietDays(): int
    {
        $value = Setting::getValue('agent_auto_quiet_days');

        return is_numeric($value) ? max(1, (int) $value) : 14;
    }

    /**
     * Increment H dormancy gate: when off, flag_attention records exactly as today (no notification).
     * Separate from agent_enabled so escalation notifications can be enabled/calibrated independently.
     */
    public static function escalationEnabled(): bool
    {
        return Setting::getValue('agent_escalation_enabled') === '1';
    }

    /**
     * Model id used for the lightweight significance-scoring gate.
     * Reads from Setting first; falls back to the Haiku id from AiConfig.
     * Setting: agent_significance_model.
     */
    public static function significanceModel(): string
    {
        $value = Setting::getValue('agent_significance_model');

        return (is_string($value) && trim($value) !== '') ? trim($value) : AiConfig::haikuModel();
    }

    /**
     * Model id used for the TechnicianAgent reasoning loop.
     * Reads from Setting first; falls back to the Opus id from AiConfig.
     * Setting: agent_model. Default: claude-opus-4-8.
     */
    public static function agentModel(): string
    {
        $value = Setting::getValue('agent_model');

        return (is_string($value) && trim($value) !== '') ? trim($value) : AiConfig::opusModel();
    }

    /** Gates the always-inject client-situation digest and agent-only situation tools (default off; agent is held-only on prod). */
    public static function situationContextEnabled(): bool
    {
        return Setting::getValue('agent_situation_context_enabled') === '1';
    }

    // ── Intake front-door ─────────────────────────────────────────────────────

    /**
     * LEGACY master intake gate. Retained for backward compatibility: it is now the
     * FALLBACK that each per-channel gate inherits when its own key is unset, and is
     * no longer read directly by any intake call site.
     *
     * Setting: intake_enabled.
     */
    public static function intakeEnabled(): bool
    {
        return Setting::getValue('intake_enabled') === '1';
    }

    /**
     * CALL-channel intake gate (psa-28j4 §3.2). Governs the two call-intake gates:
     * the CallIntakeJob dispatch in TranscriptionService and the dormancy re-check in
     * CallIntakePipeline. Closed ⇒ an inbound call creates NO ticket, leaving the
     * call→ticket decision to the external agent (no duplicate-ticket race).
     *
     * Setting: intake_call_enabled. ABSENT ⇒ inherits intake_enabled.
     */
    public static function intakeCallEnabled(): bool
    {
        return self::channelIntakeEnabled('intake_call_enabled');
    }

    /**
     * EMAIL-channel intake gate (psa-28j4 §3.2). Governs the attach-vs-create router in
     * EmailService::routeInboundEmail. Closed ⇒ the router is not consulted and inbound
     * email falls back to autoCreateTicketFromEmail exactly as before (email ticketing
     * itself is governed by the separate, older email_auto_ticket setting).
     *
     * Setting: intake_email_enabled. ABSENT ⇒ inherits intake_enabled.
     */
    public static function intakeEmailEnabled(): bool
    {
        return self::channelIntakeEnabled('intake_email_enabled');
    }

    /**
     * Shared reader for the per-channel intake gates.
     *
     * The ABSENT ⇒ inherit-legacy fallback is load-bearing: it is what lets a deployment
     * that carries only the old intake_enabled key keep TODAY'S EXACT BEHAVIOUR the moment
     * this ships — no surprise flip in either direction, on either channel.
     *
     * SAFETY: the fallback must trigger on ABSENT only — never on a present-but-'0'.
     * Do NOT collapse this to `Setting::getValue($key) === '1' || self::intakeEnabled()`:
     * that reads an explicit OFF as "unset" whenever the legacy master is on, making the
     * channel gate impossible to close — precisely the bug this split exists to fix.
     * A blank string is treated as absent (a cleared row is not a decision).
     */
    private static function channelIntakeEnabled(string $key): bool
    {
        $raw = Setting::getValue($key);

        if ($raw === null || trim((string) $raw) === '') {
            return self::intakeEnabled(); // unset ⇒ inherit the legacy master gate
        }

        return $raw === '1';
    }

    /**
     * Confidence at/above which a high-confidence ATTACH auto-applies (graduates from
     * held to auto). NULL-PRESERVING: absent/blank → null = NEVER auto-attach (held-first;
     * the safe default). Else max(0.80, (float)$v). Mirrors proposeCloseAutoThreshold —
     * absent ≠ 0.0.
     *
     * SAFETY: do NOT naively cast getValue() to float before the null-check.
     * An absent setting yields getValue() = null → (float) null = 0.0 →
     * max(0.80, 0.0) = 0.80, silently enabling auto-attach with nothing configured.
     * The null-preserving check below is intentional and load-bearing.
     *
     * Setting: intake_attach_auto_threshold. Floor when set: 0.80.
     */
    public static function intakeAttachAutoThreshold(): ?float
    {
        $v = Setting::getValue('intake_attach_auto_threshold');
        if ($v === null || trim((string) $v) === '') {
            return null; // unset = never auto-attach (the safe default)
        }

        return max(0.80, (float) $v); // floor: can't auto-attach below 0.80
    }

    /**
     * Confidence at/above which a suspected-spam UNRESOLVED call graduates from a held
     * one-tap suggestion to an AUTO mark-followed-up + block-the-number. NULL-PRESERVING:
     * absent/blank → null = NEVER auto-block (the safe default — the only semi-destructive
     * automated path in the intake leg ships off). Else max(0.90, (float)$v). Mirrors
     * intakeAttachAutoThreshold — absent ≠ 0.0.
     *
     * SAFETY: do NOT naively cast getValue() to float before the null-check.
     * An absent setting yields getValue() = null → (float) null = 0.0 →
     * max(0.90, 0.0) = 0.90, silently enabling auto-block with nothing configured.
     * The null-preserving check below is intentional and load-bearing.
     *
     * Setting: intake_spam_block_auto_threshold. Floor when set: 0.90 (auto-blocking a
     * caller demands high confidence — a higher floor than auto-attach's 0.80).
     */
    public static function intakeSpamBlockAutoThreshold(): ?float
    {
        $v = Setting::getValue('intake_spam_block_auto_threshold');
        if ($v === null || trim((string) $v) === '') {
            return null; // unset = never auto-block (the safe default)
        }

        return max(0.90, (float) $v); // floor: can't auto-block below 0.90
    }
}
