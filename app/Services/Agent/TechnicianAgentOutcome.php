<?php

namespace App\Services\Agent;

/**
 * The result of a single TechnicianAgent::run() — what the agent decided.
 *
 * Introduced for psa-3q0c so a correction-driven re-assessment that ends in a
 * "leave-it" (the agent ran but took NO action) can be surfaced to the operator
 * instead of silently vanishing. Before this, run() returned void and a leave-it
 * was indistinguishable from "did not run".
 *
 * Three shapes matter:
 *  - notAssessed(): the tool loop did NOT run (AI unconfigured/disabled, or it
 *    threw and was caught fail-soft). No conclusion to report — NOT a leave-it.
 *  - assessed + acted: the agent ran and took an action (propose_close /
 *    flag_attention / send_reply). A new run row exists; nothing to surface here.
 *  - assessed + ! acted: the agent ran and chose to LEAVE IT. `narration` carries
 *    the model's closing reasoning — the reason to show the operator.
 *
 * Not marked final (mirrors AiResponse): tests mock TechnicianAgent::run and let
 * Mockery fabricate the declared return type — a final class breaks that.
 */
class TechnicianAgentOutcome
{
    public function __construct(
        public readonly bool $assessed,
        public readonly bool $acted,
        public readonly string $narration = '',
    ) {}

    /** The agent ran but deliberately took no action — the visible "left as-is" case. */
    public function leftAsIs(): bool
    {
        return $this->assessed && ! $this->acted;
    }

    /** The tool loop never ran (unconfigured/disabled/error) — no conclusion to report. */
    public static function notAssessed(): self
    {
        return new self(assessed: false, acted: false, narration: '');
    }
}
