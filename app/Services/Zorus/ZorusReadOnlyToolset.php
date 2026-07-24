<?php

namespace App\Services\Zorus;

use App\Models\Asset;
use App\Models\Client;
use App\Services\Chet\ChetDataSurfaceTextSanitizer;
use App\Support\ZorusConfig;
use Carbon\Carbon;

/**
 * Zorus DNS-filtering read tools for the staff MCP surface (psa-5wg2i).
 *
 * Serves the recurring "user says a site is blocked" triage class: is this
 * client on Zorus at all, is filtering enabled on the machine in question,
 * which Zorus group (= filtering policy) applies, and is the agent even
 * checking in — without a human opening the Zorus console.
 *
 * READS THE SYNCED COLUMNS, NEVER THE LIVE API. ZorusDeviceSyncService already
 * lands everything these tools need on `assets` (zorus_endpoint_id, group,
 * filtering/CyberSight flags, agent state/version, last-seen). Two consequences
 * that shape everything here:
 *
 *  1. DATA BOUNDARY IS OURS ALONE. The vendor's customerUuid filter is
 *     documented unreliable (see ZorusDeviceSyncService: it fetches ALL
 *     endpoints and groups client-side), so upstream offers no per-client
 *     guarantee — the client_id scoping in these queries IS the boundary
 *     between one client's DNS posture and another's. Scope resolves from
 *     clients.zorus_customer_id via the PSA client row, never from tool input.
 *     Same boundary class the UniFi lanes fought in psa-1ynqc.
 *
 *  2. SYNCED STATE IS NOT CURRENT TRUTH (psa-wedk lesson). Every payload
 *     carries data_as_of/data_stale and each row carries last_seen_at +
 *     synced_at, so an "enabled"/"Connected" claim never travels without the
 *     timestamp that qualifies it. Empty answers explain themselves — a mapped
 *     client with zero synced endpoints, or a hostname miss, names the likely
 *     causes instead of reading as a confident all-clear.
 *
 * READ-ONLY. The staged unblock/allow-list write is deliberately a separate
 * bead (psa-1h611): Zorus's public API is read-shaped today (POST /search is
 * its read idiom), and a client-affecting write must not ride in on a read PR.
 */
class ZorusReadOnlyToolset
{
    private const CLIENT_TOOL_NAMES = [
        'zorus_get_filtering_status',
        'zorus_list_endpoints',
    ];

    /**
     * Synced data older than this is flagged stale. The device sync runs daily
     * (05:20), so >48h means at least one full sync cycle has been missed —
     * the same threshold the CIPP enrichment staleness indicator uses.
     */
    private const STALE_AFTER_HOURS = 48;

    private const DATA_SOURCE_NOTE = 'Synced Zorus data from the PSA database (refreshed by the daily Zorus device sync), not a live Zorus query — check last_seen_at and data_as_of before treating filtering or agent state as current.';

    public function __construct(
        private readonly ChetDataSurfaceTextSanitizer $textSanitizer,
    ) {}

    /** @return array<int, array<string, mixed>> */
    public static function definitions(): array
    {
        return self::clientDefinitions();
    }

    /** @return array<int, array<string, mixed>> */
    public static function clientDefinitions(): array
    {
        return [
            [
                'name' => 'zorus_get_filtering_status',
                'description' => "Get a PSA client's Zorus DNS filtering posture from the last device sync: endpoint count, how many have filtering enabled/disabled, which Zorus groups (the filtering policies) apply, CyberSight coverage, agent connection states, and how fresh the data is. Start here when a user reports a website being blocked — it answers whether this client is covered by Zorus and which policy group their machines sit in. Synced data, not a live query; the response carries data_as_of.",
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'client_id' => ['type' => 'integer', 'description' => 'PSA client ID. The client must be mapped to a Zorus customer.'],
                    ],
                    'required' => ['client_id'],
                ],
            ],
            [
                'name' => 'zorus_list_endpoints',
                'description' => "List a PSA client's Zorus-linked endpoints from the last device sync: hostname, Zorus group (the filtering policy that applies), filtering enabled, CyberSight enabled, agent state and version, last-seen time. Filter by hostname to check the specific machine a user reports a blocked site from — a miss tells you whether the asset exists in the PSA without a Zorus agent link. Synced data, not a live query.",
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'client_id' => ['type' => 'integer', 'description' => 'PSA client ID. The client must be mapped to a Zorus customer.'],
                        'hostname' => ['type' => 'string', 'description' => 'Optional case-insensitive hostname substring to find a specific machine.'],
                        'filtering_enabled' => ['type' => 'boolean', 'description' => 'Optional: true for only endpoints with DNS filtering on, false for only those with it off.'],
                        'limit' => ['type' => 'integer', 'description' => 'Max endpoints to return (default 50, max 200).'],
                    ],
                    'required' => ['client_id'],
                ],
            ],
        ];
    }

    public static function handles(string $toolName): bool
    {
        return in_array($toolName, self::CLIENT_TOOL_NAMES, true);
    }

    public static function requiresClient(string $toolName): bool
    {
        return in_array($toolName, self::CLIENT_TOOL_NAMES, true);
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function execute(string $toolName, array $input, ?int $clientId = null): array
    {
        // OFF=OFF: the master switch withdraws the capability, not just the syncs.
        if (! ZorusConfig::isAvailable()) {
            return ['error' => 'Zorus is not available in this deployment — it is either switched off or has no API key configured.'];
        }

        return match ($toolName) {
            'zorus_get_filtering_status' => $this->getFilteringStatus($input, $clientId),
            'zorus_list_endpoints' => $this->listEndpoints($input, $clientId),
            default => ['error' => "Unknown tool: {$toolName}"],
        };
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    private function getFilteringStatus(array $input, ?int $clientId): array
    {
        $client = $this->resolveMappedClient($input, $clientId);
        if (is_array($client)) {
            return $client; // error payload
        }

        $endpoints = $this->endpointQuery($client)->get([
            'zorus_group_name', 'zorus_filtering_enabled', 'zorus_cybersight_enabled',
            'zorus_agent_state', 'zorus_last_seen_at', 'zorus_synced_at',
        ]);

        $result = $this->header($client, $endpoints->max('zorus_synced_at'));
        $result['endpoint_count'] = $endpoints->count();

        if ($endpoints->isEmpty()) {
            $result['note'] = $this->emptyFleetNote($client);

            return $result;
        }

        // whereStrict: a NULL flag (sync never saw the field) must count as unknown,
        // not fold into "disabled" the way loose null == false comparison would.
        $result['filtering_enabled_count'] = $endpoints->whereStrict('zorus_filtering_enabled', true)->count();
        $result['filtering_disabled_count'] = $endpoints->whereStrict('zorus_filtering_enabled', false)->count();
        $result['filtering_unknown_count'] = $endpoints->whereNull('zorus_filtering_enabled')->count();
        $result['cybersight_enabled_count'] = $endpoints->whereStrict('zorus_cybersight_enabled', true)->count();

        $states = [];
        foreach ($endpoints as $endpoint) {
            $state = is_string($endpoint->zorus_agent_state) && trim($endpoint->zorus_agent_state) !== ''
                ? $endpoint->zorus_agent_state
                : 'unknown';
            $states[$state] = ($states[$state] ?? 0) + 1;
        }
        $result['agent_states'] = $states;

        // Group = the Zorus filtering policy bucket, the client-grain answer to
        // "which policy applies". Grouped on the raw name, sanitized on output.
        $groups = [];
        foreach ($endpoints as $endpoint) {
            $name = is_string($endpoint->zorus_group_name) && trim($endpoint->zorus_group_name) !== ''
                ? $endpoint->zorus_group_name
                : null;
            $key = $name ?? "\0unknown";
            $groups[$key] = ['name' => $name, 'endpoint_count' => ($groups[$key]['endpoint_count'] ?? 0) + 1];
        }
        uasort($groups, fn (array $a, array $b): int => [$b['endpoint_count'], $a['name'] ?? "\xff"] <=> [$a['endpoint_count'], $b['name'] ?? "\xff"]);
        $result['groups'] = array_values(array_map(fn (array $group): array => [
            'name' => $this->textSanitizer->sanitizeNullable('Zorus group name', $group['name'], 200),
            'endpoint_count' => $group['endpoint_count'],
        ], $groups));

        return $result;
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    private function listEndpoints(array $input, ?int $clientId): array
    {
        $client = $this->resolveMappedClient($input, $clientId);
        if (is_array($client)) {
            return $client;
        }

        $limit = $this->limit($input, default: 50, max: 200);
        $hostname = trim((string) ($input['hostname'] ?? ''));

        $filteringFilter = null;
        if (array_key_exists('filtering_enabled', $input)) {
            $filteringFilter = $this->booleanOrNull($input['filtering_enabled']);
            if ($filteringFilter === null) {
                return ['error' => 'filtering_enabled must be true or false.'];
            }
        }

        $fleetCount = $this->endpointQuery($client)->count();

        $query = $this->endpointQuery($client);
        if ($hostname !== '') {
            $query->whereRaw('LOWER(hostname) LIKE ?', ['%'.mb_strtolower($hostname).'%']);
        }
        if ($filteringFilter !== null) {
            $query->where('zorus_filtering_enabled', $filteringFilter);
        }

        $matching = (clone $query)->count();
        $rows = $query
            ->orderByRaw("LOWER(COALESCE(hostname, ''))")
            ->limit($limit)
            ->get([
                'id', 'hostname', 'name', 'zorus_group_name', 'zorus_filtering_enabled',
                'zorus_cybersight_enabled', 'zorus_agent_state', 'zorus_agent_version',
                'zorus_last_seen_at', 'zorus_synced_at',
            ]);

        $result = $this->header($client, $this->endpointQuery($client)->max('zorus_synced_at'));
        $result['count'] = $rows->count();
        $result['truncated'] = $matching > $rows->count();
        $result['endpoints'] = $rows->map(fn (Asset $asset): array => [
            'asset_id' => $asset->id,
            'hostname' => $asset->hostname,
            'asset_name' => $asset->name,
            'group' => $this->textSanitizer->sanitizeNullable('Zorus group name', $asset->zorus_group_name, 200),
            'filtering_enabled' => $asset->zorus_filtering_enabled,
            'cybersight_enabled' => $asset->zorus_cybersight_enabled,
            'agent_state' => $asset->zorus_agent_state,
            'agent_version' => $asset->zorus_agent_version,
            'last_seen_at' => $asset->zorus_last_seen_at?->toIso8601ZuluString(),
            'synced_at' => $asset->zorus_synced_at?->toIso8601ZuluString(),
        ])->values()->all();

        if ($fleetCount === 0) {
            $result['note'] = $this->emptyFleetNote($client);
        }

        // A hostname miss must disambiguate, not read as "nothing to see": for the
        // unblock-request class, "this machine has no Zorus agent" IS the finding.
        if ($hostname !== '' && $rows->isEmpty()) {
            $result['no_match_note'] = $this->hostnameMissNote($client, $hostname, $filteringFilter);
        }

        return $result;
    }

    // ── scoping helpers ────────────────────────────────────────────────────────

    /**
     * Resolve the PSA client for a tool call and prove it is Zorus-mapped.
     * Returns the Client, or an error payload array to hand straight back.
     *
     * The vendor-scope question ("which Zorus customer is this?") is answered
     * ONLY by clients.zorus_customer_id on the resolved row — tool input picks
     * which PSA client to ask about, never which Zorus customer to read.
     *
     * @param  array<string, mixed>  $input
     * @return Client|array<string, mixed>
     */
    private function resolveMappedClient(array $input, ?int $clientId): Client|array
    {
        $id = $clientId ?? $this->positiveInt($input['client_id'] ?? null);
        if ($id === null) {
            return ['error' => 'client_id is required'];
        }

        $client = Client::find($id);
        if ($client === null) {
            return ['error' => "PSA client {$id} was not found."];
        }

        if (empty($client->zorus_customer_id)) {
            $error = "{$client->name} is not mapped to a Zorus customer, so Zorus DNS filtering data cannot be read for this client. "
                .'Map the client in Settings > Zorus Customer Mapping, or treat this client as not covered by Zorus.';

            // An unmapped client drops out of the sync loop, so any zorus_* columns
            // it still carries stop being refreshed forever. Refuse rather than
            // serve rot — but say why the data that exists is not being served.
            if ($this->endpointQuery($client)->exists()) {
                $error .= ' Note: this client still carries leftover Zorus endpoint data from a previous mapping; it is ignored because it is no longer being refreshed.';
            }

            return ['error' => $error];
        }

        return $client;
    }

    /**
     * The one query seam every read goes through: this client's rows, nothing
     * else. With the upstream customer filter unreliable, this client_id scope
     * is the entire data boundary — do not widen it.
     *
     * @return \Illuminate\Database\Eloquent\Builder<Asset>
     */
    private function endpointQuery(Client $client): \Illuminate\Database\Eloquent\Builder
    {
        return Asset::where('client_id', $client->id)->whereNotNull('zorus_endpoint_id');
    }

    // ── staleness / empty-answer framing ───────────────────────────────────────

    /** @return array<string, mixed> */
    private function header(Client $client, mixed $dataAsOf): array
    {
        // max() over a hydrated collection hands back Carbon (cast applied); max()
        // pushed down to the query builder hands back the raw DB string. Accept both.
        $dataAsOf = match (true) {
            $dataAsOf instanceof Carbon => $dataAsOf,
            is_string($dataAsOf) && trim($dataAsOf) !== '' => Carbon::parse($dataAsOf),
            default => null,
        };
        $stale = $dataAsOf === null || $dataAsOf->lt(now()->subHours(self::STALE_AFTER_HOURS));

        $header = [
            'psa_client_id' => $client->id,
            'psa_client_name' => $client->name,
            'zorus_customer_id' => $client->zorus_customer_id,
            'data_source' => self::DATA_SOURCE_NOTE,
            'data_as_of' => $dataAsOf?->toIso8601ZuluString(),
            'data_stale' => $stale,
        ];

        if ($stale && $dataAsOf !== null) {
            $header['staleness_note'] = 'The last Zorus device sync for this client completed more than '
                .self::STALE_AFTER_HOURS." hours ago (data as of {$header['data_as_of']}). Filtering and agent state "
                .'may have changed since — verify in the Zorus console before relying on this.';
        }

        return $header;
    }

    private function emptyFleetNote(Client $client): string
    {
        return "{$client->name} is mapped to Zorus customer {$client->zorus_customer_id} but no PSA assets carry synced Zorus endpoint data. "
            .'Possible causes: the daily Zorus device sync has not run yet, no Zorus agents report under this customer, '
            .'or Zorus endpoints did not match any PSA asset by hostname. Verify in the Zorus console before treating this as no coverage.';
    }

    private function hostnameMissNote(Client $client, string $hostname, ?bool $filteringFilter): string
    {
        // The hostname may have matched fine and been excluded by the boolean
        // filter — a filter interaction, not a coverage gap. Say which it was.
        if ($filteringFilter !== null) {
            $hostnameOnly = $this->endpointQuery($client)
                ->whereRaw('LOWER(hostname) LIKE ?', ['%'.mb_strtolower($hostname).'%'])
                ->count();

            if ($hostnameOnly > 0) {
                $wanted = $filteringFilter ? 'true' : 'false';

                return "{$hostnameOnly} Zorus-linked endpoint(s) matched hostname '{$hostname}', but none with filtering_enabled = {$wanted}. Retry without the filtering_enabled filter to see them.";
            }
        }

        // The disambiguation lookup honours the same client boundary as the read:
        // only THIS client's assets may ever be named.
        $unlinked = Asset::where('client_id', $client->id)
            ->whereNull('zorus_endpoint_id')
            ->whereRaw('LOWER(hostname) LIKE ?', ['%'.mb_strtolower($hostname).'%'])
            ->orderByRaw("LOWER(COALESCE(hostname, ''))")
            ->limit(5)
            ->pluck('hostname')
            ->all();

        if ($unlinked !== []) {
            $names = implode(', ', $unlinked);

            return "No Zorus-linked endpoint matched '{$hostname}', but ".count($unlinked)." PSA asset(s) for this client match that hostname with no Zorus endpoint link: {$names}. "
                .'Either the Zorus agent is not installed on them, they have not synced yet, or they report to Zorus under a different hostname.';
        }

        return "No Zorus-linked endpoint matched '{$hostname}'. No PSA asset for this client matches that hostname either — "
            .'check the spelling, or use find_assets to search this client\'s inventory.';
    }

    // ── plumbing ───────────────────────────────────────────────────────────────

    /** @param array<string, mixed> $input */
    private function limit(array $input, int $default, int $max): int
    {
        $limit = $this->positiveInt($input['limit'] ?? null) ?? $default;

        return max(1, min($limit, $max));
    }

    private function positiveInt(mixed $value): ?int
    {
        if (is_int($value) && $value > 0) {
            return $value;
        }
        if (is_string($value) && ctype_digit($value) && (int) $value > 0) {
            return (int) $value;
        }

        return null;
    }

    private function booleanOrNull(mixed $value): ?bool
    {
        if (is_bool($value)) {
            return $value;
        }
        if (is_string($value) && in_array(strtolower($value), ['true', 'false'], true)) {
            return strtolower($value) === 'true';
        }

        return null;
    }
}
