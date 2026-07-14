<?php

namespace App\Services\Signals;

use App\Models\SignalInboxEntry;
use App\Models\SignalRoute;
use App\Support\McpConfig;

/**
 * The D1 payload-piggyback nudge (psa-0j6i). Baseline delivery is queue-always: every
 * relayed alert lands in the token's signal_inbox for the agent's own poll cadence. Some
 * types are additionally flagged "also nudge" (the per-cell nudge_types, D5) — for those,
 * an active agent should be made aware promptly WITHOUT a GC-specific wake.
 *
 * The mechanism: when a token holding poll_signals has an UNACKED inbox entry whose type is
 * in that token's nudge set, McpStaffController piggybacks a short "you have unread alerts"
 * notice onto the normal response payload of the agent's NEXT PSA tool call. Agent-agnostic,
 * no GC coupling.
 *
 * DERIVED, not a stored flag: the answer is computed live from the inbox + the route's
 * nudge_types, so it self-clears the moment the agent polls and acks — nothing to reset,
 * and it keeps reminding on every tool call until the queue is drained.
 */
class SignalNudgeNotice
{
    private const POLL_SIGNALS_TOOL = 'poll_signals';

    /**
     * A piggyback notice for this token, or null when there is nothing nudge-worthy to say.
     *
     * Gated on consumability: a token that cannot use poll_signals can't act on the notice,
     * so it is never emitted (dead noise, and worse, an un-actionable prompt).
     */
    public function pendingNoticeFor(?string $tokenLabel): ?string
    {
        $tokenLabel = trim((string) $tokenLabel);
        if ($tokenLabel === '') {
            return null;
        }

        if (! McpConfig::labelCanUseBridgeTool($tokenLabel, self::POLL_SIGNALS_TOOL)) {
            return null;
        }

        $nudgeTypes = $this->nudgeTypesFor($tokenLabel);
        if ($nudgeTypes === []) {
            return null;
        }

        $unacked = SignalInboxEntry::query()
            ->whereNull('acked_at')
            ->whereHas('destination', fn ($query) => $query->where('mcp_token_label', $tokenLabel));

        $hasNudgeWorthy = (clone $unacked)
            ->whereHas('event', fn ($query) => $query->whereIn('type_key', $nudgeTypes))
            ->exists();

        if (! $hasNudgeWorthy) {
            return null;
        }

        $total = (clone $unacked)->count();

        return "[PSA] You have {$total} unread alert(s) — call {$this->pollToolName()} to read them.";
    }

    /**
     * The token's also-nudge set: the nudge_types on its matrix-owned relay route. Empty
     * when the token has no relay route or no type is flagged to nudge.
     *
     * @return array<int, string>
     */
    private function nudgeTypesFor(string $tokenLabel): array
    {
        $route = SignalRoute::query()->where('managed_token_label', $tokenLabel)->first();
        if ($route === null) {
            return [];
        }

        $filter = is_array($route->event_filter) ? $route->event_filter : [];
        $nudge = $filter['nudge_types'] ?? [];

        return is_array($nudge)
            ? array_values(array_unique(array_map('strval', $nudge)))
            : [];
    }

    private function pollToolName(): string
    {
        return self::POLL_SIGNALS_TOOL;
    }
}
