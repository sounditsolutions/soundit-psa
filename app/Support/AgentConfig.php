<?php

namespace App\Support;

use App\Models\Setting;

/**
 * Setting-backed config for the Backlog Agent (Increment 1).
 * Ships dormant: enabled() defaults false, proposeCloseAutoThreshold() defaults null.
 * Mirrors TechnicianConfig idioms — except the auto-threshold reader which is
 * deliberately null-preserving (absent ≠ 0.0, it means "never auto-close").
 */
class AgentConfig
{
    /** Master on/off for the Backlog Agent. Absent ⇒ false (dormant by default). */
    public static function enabled(): bool
    {
        return (bool) Setting::getValue('backlog_agent_enabled');
    }

    /**
     * Maximum number of propose-close proposals that may sit pending approval
     * at one time. Setting: backlog_agent_max_pending. Default: 10. Floor: 1.
     */
    public static function maxPendingProposals(): int
    {
        $value = Setting::getValue('backlog_agent_max_pending');

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
     * Model id used for the lightweight significance-scoring gate.
     * Reads from Setting first; falls back to the Haiku id from AiConfig.
     * Setting: backlog_agent_significance_model.
     */
    public static function significanceModel(): string
    {
        $value = Setting::getValue('backlog_agent_significance_model');

        return (is_string($value) && trim($value) !== '') ? trim($value) : AiConfig::haikuModel();
    }
}
