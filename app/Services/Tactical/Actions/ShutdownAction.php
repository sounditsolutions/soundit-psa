<?php

namespace App\Services\Tactical\Actions;

use App\Services\Tactical\TacticalClient;

/**
 * Shut down a Tactical agent — DESTRUCTIVE, and more consequential than reboot:
 * the box stays OFF and cannot be powered back on remotely. isDestructive() ===
 * true, so the bus requires a valid confirm token before it will execute.
 *
 * Deliberately NOT sharing a base class with RebootAction (amendment D6): the
 * ~6 trivial lines are duplicated rather than abstracted into a
 * ScalarResponseAction parent — the bus is the only shared spine, and a shared
 * action base would couple two actions whose only commonality is being short.
 *
 * Side-effect-free w.r.t. PSA models (m5): it only calls TacticalClient::shutdown
 * and returns the normalized result. The bus catches/classifies a transport
 * failure (offline) vs an HTTP error (error).
 */
class ShutdownAction implements TacticalAction
{
    public function key(): string
    {
        return 'tactical.shutdown';
    }

    public function isDestructive(): bool
    {
        return true;
    }

    /**
     * Shutdown takes no parameters.
     *
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    public function validateParams(array $params): array
    {
        return [];
    }

    /**
     * Amendment D2 (verbatim, binding): the device-specific irreversibility
     * consequence. PSA cannot reliably know an asset's out-of-band recovery
     * method, so the generic "no remote power-on" warning is stated for every
     * shutdown — and this exact text lands in BOTH the confirm modal AND the
     * persisted audit message (the person reading the log later isn't the clicker).
     */
    public function summary(array $params): string
    {
        return 'Shut down the device now — this device powers off and cannot be powered back on '
            .'remotely; recovery requires physical/IPMI access.';
    }

    public function execute(TacticalClient $client, string $agentId, array $params): TacticalActionResult
    {
        $client->shutdown($agentId);

        return TacticalActionResult::ok('Shutdown command sent.');
    }
}
