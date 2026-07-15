<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * psa-lunj — heal relay routes the un-guarded Routes page silently disabled.
 *
 * Until this branch, /settings/alerts listed matrix-owned routes (managed_token_label IS
 * NOT NULL) indistinguishably among operator routes and let them be toggled. Toggling one
 * off stopped delivery (SignalRouter filters ->where('enabled', true)) while the relay
 * matrix went on rendering the cell as relayed — a silent drop on the lane feeding Chet's
 * wake-on-alert. The branch guards every operator door, but guarding the door does nothing
 * for a row already broken behind it: worse, it removes the only control the operator had
 * to switch it back on, so without this migration the fix would ENTRENCH the outage.
 *
 * WHY THIS IS A SAFE HEAL AND NOT A GUESS AT INTENT. For a matrix-owned route `enabled` is
 * DERIVED, never configured:
 *   - SignalRelayMatrix::setRelay  -> `$route->enabled = $types !== []`
 *   - SignalRelayMatrix::relayRouteFor -> creates enabled=false WITH types=[] (consistent)
 *   - SignalRelayMatrix::setNudge  -> never touches enabled
 * and the matrix UI exposes no enable/disable control at all. The invariant is therefore
 * `enabled == (types !== [])`, and a row with non-empty types AND enabled=false is a state
 * the OWNER CANNOT PRODUCE OR EXPRESS. The un-guarded toggle was its only possible source.
 * So this restores the owner's own invariant on rows that were corrupted; it does not
 * override an operator's choice, because that choice was never expressible here. An
 * operator who wants a type to stop relaying un-ticks the CELL, which empties types and
 * disables the route consistently.
 *
 * Scoped as narrowly as the reasoning: managed routes ONLY (managed_token_label IS NOT
 * NULL) with a non-empty types list. Operator-authored routes (managed_token_label IS NULL)
 * are never touched — a disabled operator route is a legitimate, expressible choice.
 *
 * Deliberately NOT reversible: down() would re-break the rows it just healed, re-creating a
 * silent alert drop. The forward state is the invariant the owner defines.
 */
return new class extends Migration
{
    public function up(): void
    {
        $healed = [];

        DB::table('signal_routes')
            ->whereNotNull('managed_token_label')
            ->where('enabled', false)
            ->orderBy('id')
            ->each(function (object $row) use (&$healed): void {
                $filter = json_decode((string) $row->event_filter, true);
                $types = is_array($filter) ? ($filter['types'] ?? []) : [];

                // Empty types + disabled is the CONSISTENT resting state — leave it alone.
                if (! is_array($types) || $types === []) {
                    return;
                }

                DB::table('signal_routes')->where('id', $row->id)->update(['enabled' => true]);
                $healed[] = ['route_id' => $row->id, 'token' => $row->managed_token_label, 'types' => $types];
            });

        if ($healed !== []) {
            // Loud on purpose: these rows were silently NOT relaying, so the repair is a
            // real change in delivery behaviour on this deploy and must be visible in the
            // log rather than inferred from the diff.
            Log::warning('[Signals] psa-lunj: re-enabled matrix-owned relay routes that had been disabled outside the matrix', [
                'count' => count($healed),
                'routes' => $healed,
            ]);
        }
    }

    public function down(): void
    {
        // Intentionally empty — see the class docblock. Re-disabling would restore a
        // silent alert drop.
    }
};
