<?php

namespace App\Services\Tactical\Actions;

use App\Services\Tactical\TacticalClient;

/**
 * One endpoint-affecting Tactical operation (run-script, reboot, …). The bus
 * (TacticalActionService, T5) drives every implementation through the same
 * resolve → authorize → validate → confirm → execute → audit pipeline
 * (spec §5.1).
 *
 * Implementations MUST be side-effect-free with respect to PSA models — they
 * talk only to the TacticalClient and return a normalized result; the bus owns
 * auditing and any PSA-side effects (amendment m5).
 */
interface TacticalAction
{
    /** Stable identifier, e.g. "tactical.run_script" / "tactical.reboot". */
    public function key(): string;

    /**
     * Destructive actions (reboot/shutdown/ad-hoc cmd) require a confirm token
     * at the bus; curated-library run-script / reads do not.
     */
    public function isDestructive(): bool;

    /**
     * Validate + normalize the supplied params, returning the canonical params
     * the action will execute with (e.g. argv-tokenized args). MUST throw
     * {@see InvalidActionParams} on invalid input — the bus turns that into a
     * `rejected` result and does NOT execute (spec §11/m2).
     *
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    public function validateParams(array $params): array;

    /**
     * A human-readable, secret-redacted description of what WILL run — used for
     * the destructive confirm UI and the audit row. Receives already-normalized
     * params (post-validateParams).
     *
     * @param  array<string, mixed>  $params
     */
    public function summary(array $params): string;

    /**
     * Perform the action against the agent and return a normalized result. May
     * throw {@see \App\Services\Tactical\TacticalClientException}; the bus
     * catches and classifies it (offline vs error) — implementations need not.
     *
     * @param  array<string, mixed>  $params  already normalized by validateParams()
     */
    public function execute(TacticalClient $client, string $agentId, array $params): TacticalActionResult;
}
