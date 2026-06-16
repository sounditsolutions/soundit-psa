<?php

namespace App\Services\Tactical\Actions;

use App\Services\Tactical\TacticalClient;

/**
 * Toggle an agent's maintenance mode (alert suppression). NON-destructive
 * (reversible, no irreversible blast radius) → audited, no confirm token.
 *
 * `enabled` is REQUIRED (a missing flag is an error, not a silent default) and
 * coerced to a strict bool so both the JSON `true`/`false` and the HTML form
 * `"1"`/`"0"`/`"on"`/`"off"` shapes resolve correctly. execute() drives the D3
 * partial-PUT (TacticalClient::setMaintenance) — no read-modify-write.
 *
 * Side-effect-free w.r.t. PSA models (m5): it only calls
 * TacticalClient::setMaintenance. (The visible "Maintenance — alerts muted"
 * badge from E3 is a later UI chunk reading tactical_assets.maintenance_mode.)
 */
class SetMaintenanceAction implements TacticalAction
{
    public function key(): string
    {
        return 'tactical.set_maintenance';
    }

    public function isDestructive(): bool
    {
        return false;
    }

    /**
     * @param  array<string, mixed>  $params
     * @return array{enabled: bool}
     */
    public function validateParams(array $params): array
    {
        // Presence is required (distinguish "absent" from a falsy "disable").
        if (! array_key_exists('enabled', $params)) {
            throw new InvalidActionParams('enabled is required (true to enable, false to disable maintenance mode).');
        }

        // Strict-bool coercion: handles bool, "1"/"0", "true"/"false", "on"/"off",
        // "yes"/"no", 1/0 (FILTER_NULL_ON_FAILURE leaves a genuinely ambiguous
        // value as null so we can reject it rather than guess).
        $enabled = filter_var($params['enabled'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
        if ($enabled === null) {
            throw new InvalidActionParams('enabled must be a boolean (true/false).');
        }

        return ['enabled' => $enabled];
    }

    public function summary(array $params): string
    {
        $enabled = (bool) ($params['enabled'] ?? false);

        return ($enabled ? 'Enable' : 'Disable').' maintenance mode';
    }

    public function execute(TacticalClient $client, string $agentId, array $params): TacticalActionResult
    {
        $enabled = (bool) ($params['enabled'] ?? false);

        $client->setMaintenance($agentId, $enabled);

        return TacticalActionResult::ok(
            $enabled ? 'Maintenance mode enabled.' : 'Maintenance mode disabled.'
        );
    }
}
