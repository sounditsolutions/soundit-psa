<?php

namespace App\Services\Tactical\Actions;

use App\Services\Tactical\TacticalClient;

/**
 * Reboot a Tactical agent — the first DESTRUCTIVE control-plane action shipped
 * through the bus (spec §5.1, §11 carry-forward). isDestructive() === true, so
 * the bus requires a valid confirm token bound to {action,agent,actor} before
 * it will execute.
 *
 * Side-effect-free w.r.t. PSA models (m5): it only calls TacticalClient::reboot
 * and returns the normalized result. The bus catches/classifies a transport
 * failure as `offline` (a normal, safe outcome — rebooting an already-offline
 * box is not an error page) vs an HTTP error as `error` (M2).
 */
class RebootAction implements TacticalAction
{
    public function key(): string
    {
        return 'tactical.reboot';
    }

    public function isDestructive(): bool
    {
        return true;
    }

    /**
     * Reboot takes no parameters.
     *
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    public function validateParams(array $params): array
    {
        return [];
    }

    public function summary(array $params): string
    {
        return 'Reboot the device now';
    }

    public function execute(TacticalClient $client, string $agentId, array $params): TacticalActionResult
    {
        $client->reboot($agentId);

        return TacticalActionResult::ok('Reboot command sent.');
    }
}
