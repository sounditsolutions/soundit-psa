<?php

namespace App\Services\Tactical\Actions;

use App\Services\Tactical\TacticalClient;

/**
 * Recover an agent's services (spec §3: POST /agents/<id>/recover/). NON-
 * destructive (no irreversible blast radius) → audited, no confirm token.
 *
 * Amendment D4: P3 ships `mode=mesh` ONLY — it is synchronous and reports the
 * real outcome. `mode=tacagent` is async upstream and is REJECTED here: PSA must
 * never fire an untrackable async call the UI might present as completed. Async
 * recover + result surfacing ships with the bulk/async phase (psa-d76b).
 *
 * Side-effect-free w.r.t. PSA models (m5): it only calls TacticalClient::recover.
 */
class RecoverAction implements TacticalAction
{
    private const DEFAULT_MODE = 'mesh';

    public function key(): string
    {
        return 'tactical.recover';
    }

    public function isDestructive(): bool
    {
        return false;
    }

    /**
     * @param  array<string, mixed>  $params
     * @return array{mode: string}
     */
    public function validateParams(array $params): array
    {
        $mode = $params['mode'] ?? self::DEFAULT_MODE;

        if ($mode === 'tacagent') {
            // D4: explicitly recognized but deferred — distinct message from a
            // generic unknown-mode rejection so the UI/operator knows it is coming.
            throw new InvalidActionParams(
                'async recover (mode=tacagent) ships with the bulk/async phase (psa-d76b).'
            );
        }

        if ($mode !== self::DEFAULT_MODE) {
            throw new InvalidActionParams('mode must be mesh.');
        }

        return ['mode' => self::DEFAULT_MODE];
    }

    public function summary(array $params): string
    {
        $mode = (string) ($params['mode'] ?? self::DEFAULT_MODE);

        return "Recover agent services ({$mode})";
    }

    public function execute(TacticalClient $client, string $agentId, array $params): TacticalActionResult
    {
        $raw = $client->recover($agentId, (string) ($params['mode'] ?? self::DEFAULT_MODE));

        // Shape-safe (the reboot lesson): recover may reply with a scalar message
        // or an object — normalize either to a stdout-only ok result.
        if (is_array($raw)) {
            $stdout = $raw['stdout'] ?? $raw['output'] ?? $raw['message'] ?? '';

            return TacticalActionResult::ok((string) $stdout);
        }

        return TacticalActionResult::ok(is_scalar($raw) ? (string) $raw : '');
    }
}
