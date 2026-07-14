<?php

namespace App\Services\Signals;

use App\Models\McpToken;
use App\Models\SignalConfigLog;
use App\Models\SignalDestination;
use App\Models\SignalEvent;
use App\Models\SignalEventTypeSetting;
use App\Models\SignalRoute;
use App\Support\McpConfig;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * The Alerts-Hub relay matrix (psa-0j6i): a thin editor over per-token relay routes.
 *
 * The surface is a catalog(rows = every signal event type) × token(columns = active MCP
 * tokens) matrix. A cell (type T, token K) is "relayed" when T is in K's relay route's
 * event_filter.types, and additionally "nudge" when T is in event_filter.nudge_types
 * (nudge_types ⊆ types — the D5 per-cell also-nudge flag). Toggling a cell find-or-creates
 * K's matrix-owned relay route + its mcp destination + step and edits the two lists.
 *
 * Reuses the existing route model rather than a second delivery mechanism, so relay
 * inherits suppression, the rate cap, cooldown, revoke-cascade and poll_signals-
 * consumability for free. Matrix-owned routes are exactly those with a non-null
 * managed_token_label; operator-authored routes (managed_token_label IS NULL, every route
 * today incl. the legacy seed) are NEVER read or written here, so existing behaviour is
 * byte-identical.
 *
 * The D4 global per-type master toggle (SignalEventTypeSetting) gates the write path: a
 * globally-disabled type refuses to be relayed. Every edit is audited to SignalConfigLog
 * (D3).
 */
class SignalRelayMatrix
{
    /**
     * The full grid for the editor UI.
     *
     * @return array{
     *     types: array<int, array{key:string, label:string, routable:bool, live:bool, globally_enabled:bool}>,
     *     tokens: array<int, array{label:string, has_poll_signals:bool}>,
     *     cells: array<string, array<string, array{relayed:bool, nudge:bool}>>,
     * }
     */
    public function matrix(): array
    {
        $seenTypeKeys = SignalEvent::query()->distinct()->pluck('type_key')->all();
        $seen = array_fill_keys($seenTypeKeys, true);

        $types = [];
        foreach (SignalEventTypes::all() as $key => $meta) {
            $types[] = [
                'key' => $key,
                'label' => (string) $meta['label'],
                'routable' => (bool) ($meta['routable'] ?? false),
                'live' => isset($seen[$key]),
                'globally_enabled' => SignalEventTypeSetting::isTypeGloballyEnabled($key),
            ];
        }

        $tokenLabels = McpToken::query()
            ->active()
            ->orderBy('label')
            ->pluck('label')
            ->map(fn ($label): string => (string) $label)
            ->filter(fn (string $label): bool => $label !== '')
            ->unique()
            ->values();

        // One read of the matrix-owned routes, indexed by token label.
        $routesByLabel = SignalRoute::query()
            ->whereNotNull('managed_token_label')
            ->get()
            ->keyBy('managed_token_label');

        $tokens = [];
        $cells = [];
        foreach ($tokenLabels as $label) {
            $tokens[] = [
                'label' => $label,
                'has_poll_signals' => McpConfig::labelCanUseBridgeTool($label, 'poll_signals'),
            ];

            $route = $routesByLabel->get($label);
            $relayed = array_fill_keys($this->routeTypes($route), true);
            $nudged = array_fill_keys($this->routeNudgeTypes($route), true);

            $cells[$label] = [];
            foreach ($types as $type) {
                $cells[$label][$type['key']] = [
                    'relayed' => isset($relayed[$type['key']]),
                    'nudge' => isset($nudged[$type['key']]),
                ];
            }
        }

        return ['types' => $types, 'tokens' => $tokens, 'cells' => $cells];
    }

    /** Turn relay of a type on/off for a token (queue-always — the baseline delivery path). */
    public function setRelay(string $tokenLabel, string $typeKey, bool $on, ?int $userId = null): void
    {
        $tokenLabel = $this->assertValidTokenLabel($tokenLabel);
        $this->assertRoutableCatalogType($typeKey);

        if ($on && ! SignalEventTypeSetting::isTypeGloballyEnabled($typeKey)) {
            throw new \InvalidArgumentException("Type {$typeKey} is globally disabled and cannot be relayed.");
        }

        DB::transaction(function () use ($tokenLabel, $typeKey, $on, $userId): void {
            // Turning a cell OFF for a token that has no relay route yet is a no-op — never
            // create an empty managed route just to disable it.
            $route = $on
                ? $this->relayRouteFor($tokenLabel)
                : SignalRoute::query()->where('managed_token_label', $tokenLabel)->first();
            if ($route === null) {
                return;
            }

            $filter = is_array($route->event_filter) ? $route->event_filter : [];
            $types = $this->stringList($filter['types'] ?? []);
            $nudge = $this->stringList($filter['nudge_types'] ?? []);

            if ($on) {
                $types = $this->withValue($types, $typeKey);
            } else {
                $types = $this->withoutValue($types, $typeKey);
                // Invariant nudge_types ⊆ types: a type that no longer relays cannot nudge.
                $nudge = $this->withoutValue($nudge, $typeKey);
            }

            $filter['types'] = $types;
            $filter['nudge_types'] = $nudge;
            $route->event_filter = $filter;
            $route->enabled = $types !== [];
            $route->save();

            $this->audit($userId, $on ? 'relay_added' : 'relay_removed', $route, [
                'token_label' => $tokenLabel,
                'type_key' => $typeKey,
                'types' => $types,
            ]);
        });
    }

    /** Turn the also-nudge flag on/off for an already-relayed cell (D5, per-cell). */
    public function setNudge(string $tokenLabel, string $typeKey, bool $on, ?int $userId = null): void
    {
        $tokenLabel = $this->assertValidTokenLabel($tokenLabel);
        $this->assertRoutableCatalogType($typeKey);

        DB::transaction(function () use ($tokenLabel, $typeKey, $on, $userId): void {
            $route = SignalRoute::query()->where('managed_token_label', $tokenLabel)->first();
            $types = $this->routeTypes($route);

            if ($route === null || ! in_array($typeKey, $types, true)) {
                throw new \InvalidArgumentException("Type {$typeKey} must be relayed to {$tokenLabel} before it can also-nudge.");
            }

            $filter = is_array($route->event_filter) ? $route->event_filter : [];
            $nudge = $this->stringList($filter['nudge_types'] ?? []);
            $nudge = $on ? $this->withValue($nudge, $typeKey) : $this->withoutValue($nudge, $typeKey);

            $filter['nudge_types'] = $nudge;
            $route->event_filter = $filter;
            $route->save();

            $this->audit($userId, $on ? 'nudge_added' : 'nudge_removed', $route, [
                'token_label' => $tokenLabel,
                'type_key' => $typeKey,
                'nudge_types' => $nudge,
            ]);
        });
    }

    /** Flip the D4 global per-type master toggle. A disabled type cannot be relayed anywhere. */
    public function setTypeGlobalEnabled(string $typeKey, bool $enabled, ?int $userId = null): void
    {
        $this->assertRoutableCatalogType($typeKey);

        $setting = SignalEventTypeSetting::setGlobalEnabled($typeKey, $enabled);

        $this->audit($userId, 'type_global_toggle', $setting, [
            'type_key' => $typeKey,
            'enabled' => $enabled,
        ]);
    }

    /**
     * Find-or-create the matrix-owned relay route for a token: its dedicated mcp
     * destination + a single step + the route itself. Idempotent — a token has exactly
     * one matrix relay route, keyed by managed_token_label.
     */
    private function relayRouteFor(string $tokenLabel): SignalRoute
    {
        $route = SignalRoute::query()->where('managed_token_label', $tokenLabel)->first();
        if ($route !== null) {
            return $route->loadMissing('steps');
        }

        // Create the ROUTE first, so the unique key on managed_token_label trips BEFORE any
        // destination is written — a lost concurrent first-toggle race leaves no orphan.
        try {
            $route = SignalRoute::create([
                'label' => "Relay to {$tokenLabel}",
                'managed_token_label' => $tokenLabel,
                'event_filter' => ['types' => [], 'nudge_types' => []],
                'enabled' => false,
                // Queue-always: no cooldown suppression on the matrix relay path (the
                // 60/type/hr rate cap remains the flood backstop).
                'cooldown_seconds' => 0,
            ]);
        } catch (UniqueConstraintViolationException) {
            // Another request already created this token's one managed route — use theirs
            // (the unique key guarantees exactly one, so this is the race-resistant path).
            return SignalRoute::query()->where('managed_token_label', $tokenLabel)->firstOrFail()->loadMissing('steps');
        }

        $destination = SignalDestination::create([
            'label' => "Relay to {$tokenLabel}",
            'type' => 'mcp',
            'mcp_token_label' => $tokenLabel,
            'enabled' => true,
        ]);

        $route->steps()->create(['step_order' => 1, 'destination_id' => $destination->id]);

        return $route->load('steps');
    }

    /**
     * Validate + normalise an MCP token label before ANY relay route/destination/step write.
     * The write authority lives HERE (not in the controller/form): a crafted authenticated
     * POST must not be able to pre-arm matrix config for a nonexistent, revoked, malformed or
     * oversized token label. Requires a LIVE (non-revoked) token — the same predicate that
     * builds the matrix columns (McpToken::active()) — so the write surface can never exceed
     * the read surface. Returns the trimmed label used for storage/lookup.
     */
    private function assertValidTokenLabel(string $tokenLabel): string
    {
        $label = trim($tokenLabel);

        if ($label === '' || mb_strlen($label) > 255) {
            throw new \InvalidArgumentException('Invalid MCP token label.');
        }

        if (! McpToken::hasLiveLabel($label)) {
            throw new \InvalidArgumentException("Unknown or revoked MCP token label: {$label}");
        }

        return $label;
    }

    /** @return array<int, string> */
    private function routeTypes(?SignalRoute $route): array
    {
        $filter = $route !== null && is_array($route->event_filter) ? $route->event_filter : [];

        return $this->stringList($filter['types'] ?? []);
    }

    /** @return array<int, string> */
    private function routeNudgeTypes(?SignalRoute $route): array
    {
        $filter = $route !== null && is_array($route->event_filter) ? $route->event_filter : [];

        return $this->stringList($filter['nudge_types'] ?? []);
    }

    private function assertRoutableCatalogType(string $typeKey): void
    {
        if (! SignalEventTypes::has($typeKey)) {
            throw new \InvalidArgumentException("Unknown signal event type: {$typeKey}");
        }

        if (! SignalEventTypes::routable($typeKey)) {
            throw new \InvalidArgumentException("Signal event type {$typeKey} is not routable.");
        }
    }

    /**
     * @param  mixed  $value
     * @return array<int, string>
     */
    private function stringList($value): array
    {
        if (! is_array($value)) {
            return [];
        }

        return array_values(array_unique(array_map('strval', $value)));
    }

    /**
     * @param  array<int, string>  $list
     * @return array<int, string>
     */
    private function withValue(array $list, string $value): array
    {
        return in_array($value, $list, true) ? $list : array_values([...$list, $value]);
    }

    /**
     * @param  array<int, string>  $list
     * @return array<int, string>
     */
    private function withoutValue(array $list, string $value): array
    {
        return array_values(array_filter($list, fn (string $item): bool => $item !== $value));
    }

    private function audit(?int $userId, string $action, \Illuminate\Database\Eloquent\Model $subject, array $changes): void
    {
        SignalConfigLog::record($userId, $action, $subject, $changes);
    }
}
